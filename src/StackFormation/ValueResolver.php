<?php

namespace StackFormation;

use Aws\CloudFormation\Exception\CloudFormationException;
use StackFormation\Exception\MissingEnvVarException;
use StackFormation\Exception\StackNotFoundException;
use StackFormation\Profile\Manager;

class ValueResolver {

    protected $dependencyTracker;
    protected $profileManager;
    protected $config;
    protected $forceProfile;

    /**
     * PlaceholderResolver constructor.
     *
     * @param DependencyTracker $dependencyTracker
     * @param Manager $profileManager
     * @param Config $config
     * @param string $forceProfile
     */
    public function __construct(DependencyTracker $dependencyTracker=null, Manager $profileManager=null, Config $config, $forceProfile=null)
    {
        $this->dependencyTracker = $dependencyTracker ? $dependencyTracker : new DependencyTracker();
        $this->profileManager = $profileManager ? $profileManager : new Manager();
        $this->forceProfile = $forceProfile;
        $this->config = $config;
    }

    /**
     * Resolve placeholders
     *
     * @param $string
     * @param Blueprint|null $sourceBlueprint
     * @param null $sourceType
     * @param null $sourceKey
     * @param int $circuitBreaker
     * @return mixed
     * @throws \Exception
     */
    public function resolvePlaceholders($string, Blueprint $sourceBlueprint=null, $sourceType=null, $sourceKey=null, $circuitBreaker=0)
    {
        if ($circuitBreaker > 20) {
            throw new \Exception('Max nesting level reached. Looks like a circular dependency.');
        }

        $originalString = $string;

        $exceptionMsgAppendix = $this->getExceptionMessageAppendix($sourceBlueprint, $sourceType, $sourceKey);

        $string = $this->switchProfile($string, $sourceBlueprint, $sourceType, $sourceKey, $exceptionMsgAppendix);

        $string = $this->resolveEnv($string, $sourceBlueprint, $sourceType, $sourceKey, $exceptionMsgAppendix);
        $string = $this->resolveEnvWithFallback($string, $sourceBlueprint, $sourceType, $sourceKey);
        $string = $this->resolveVar($string, $sourceBlueprint, $exceptionMsgAppendix);
        $string = $this->resolveConditionalValue($string, $sourceBlueprint);
        $string = $this->resolveTstamp($string);
        $string = $this->resolveOutput($string, $sourceBlueprint, $sourceType, $sourceKey, $exceptionMsgAppendix);
        $string = $this->resolveResource($string, $sourceBlueprint, $sourceType, $sourceKey, $exceptionMsgAppendix);
        $string = $this->resolveParameter($string, $sourceBlueprint, $sourceType, $sourceKey, $exceptionMsgAppendix);
        $string = $this->resolveClean($string);

        // recursively continue until everything is replaced
        if ($string != $originalString) {
            $string = $this->resolvePlaceholders($string, $sourceBlueprint, $sourceType, $sourceKey, $circuitBreaker+1);
        }

        return $string;
    }

    public function getDependencyTracker()
    {
        return $this->dependencyTracker;
    }

    /**
     * {env:...}
     *
     * @param $string
     * @param Blueprint $sourceBlueprint
     * @param $sourceType
     * @param $sourceKey
     * @param $exceptionMsgAppendix
     * @return mixed
     */
    protected function resolveEnv($string, Blueprint $sourceBlueprint=null, $sourceType=null, $sourceKey=null, $exceptionMsgAppendix)
    {
        $string = preg_replace_callback(
            '/\{env:([^:\}\{]+?)\}/',
            function ($matches) use ($exceptionMsgAppendix, $sourceBlueprint, $sourceType, $sourceKey) {
                $value = getenv($matches[1]);
                if (!$value) {
                    throw new MissingEnvVarException($matches[1], $exceptionMsgAppendix);
                }
                $this->dependencyTracker->trackEnvUsage($matches[1], false, $value, $sourceBlueprint, $sourceType, $sourceKey);
                return getenv($matches[1]);
            },
            $string
        );
        return $string;
    }

    /**
     * {env:...:...} (with default value if env var is not set)
     *
     * @param $string
     * @param Blueprint $sourceBlueprint
     * @param $sourceType
     * @param $sourceKey
     * @return mixed
     */
    protected function resolveEnvWithFallback($string, Blueprint $sourceBlueprint=null, $sourceType=null, $sourceKey=null)
    {
        $string = preg_replace_callback(
            '/\{env:([^:\}\{]+?):([^:\}\{]+?)\}/',
            function ($matches) use ($sourceBlueprint, $sourceType, $sourceKey) {
                $value = getenv($matches[1]);
                $value = $value ? $value : $matches[2];
                $this->dependencyTracker->trackEnvUsage($matches[1], true, $value, $sourceBlueprint, $sourceType, $sourceKey);
                return $value;
            },
            $string
        );
        return $string;
    }

    /**
     * {var:...}
     *
     * @param $string
     * @param Blueprint $sourceBlueprint
     * @param $exceptionMsgAppendix
     * @return mixed
     */
    protected function resolveVar($string, Blueprint $sourceBlueprint=null, $exceptionMsgAppendix)
    {
        $string = preg_replace_callback(
            '/\{var:([^:\}\{]+?)\}/',
            function ($matches) use ($sourceBlueprint, $exceptionMsgAppendix) {
                $vars = $this->config->getGlobalVars();
                if ($sourceBlueprint) {
                    $vars = array_merge($vars, $sourceBlueprint->getVars());
                }
                if (!isset($vars[$matches[1]])) {
                    throw new \Exception("Variable '{$matches[1]}' not found$exceptionMsgAppendix");
                }
                $value = $vars[$matches[1]];
                if (is_array($value)) {
                    $value = $this->resolveConditionalValue($value, $sourceBlueprint);
                }
                return $value;
            },
            $string
        );
        return $string;
    }

    /**
     * {tstamp}
     *
     * @param $string
     * @return mixed
     */
    protected function resolveTstamp($string)
    {
        static $time;
        if (!isset($time)) {
            $time = time();
        }
        $string = str_replace('{tstamp}', $time, $string);
        return $string;
    }

    /**
     * {output:...:...}
     *
     * @param $string
     * @param Blueprint $sourceBlueprint
     * @param $sourceType
     * @param $sourceKey
     * @param $exceptionMsgAppendix
     * @return mixed
     */
    protected function resolveOutput($string, Blueprint $sourceBlueprint=null, $sourceType=null, $sourceKey=null, $exceptionMsgAppendix)
    {
        $string = preg_replace_callback(
            '/\{output:([^:\}\{]+?):([^:\}\{]+?)\}/',
            function ($matches) use ($exceptionMsgAppendix, $sourceBlueprint, $sourceType, $sourceKey) {
                try {
                    $this->dependencyTracker->trackStackDependency('output', $matches[1], $matches[2], $sourceBlueprint, $sourceType, $sourceKey);
                    return $this->getStackFactory($sourceBlueprint)->getStackOutput($matches[1], $matches[2]);
                } catch (StackNotFoundException $e) {
                    throw new \Exception("Error resolving '{$matches[0]}'$exceptionMsgAppendix", 0, $e);
                } catch (CloudFormationException $e) {
                    $extractedMessage = Helper::extractMessage($e);
                    throw new \Exception("Error resolving '{$matches[0]}'$exceptionMsgAppendix (CloudFormation error: $extractedMessage)");
                }
            },
            $string
        );
        return $string;
    }

    /**
     * {resource:...:...}
     *
     * @param $string
     * @param Blueprint $sourceBlueprint
     * @param $sourceType
     * @param $sourceKey
     * @param $exceptionMsgAppendix
     * @return mixed
     */
    protected function resolveResource($string, Blueprint $sourceBlueprint=null, $sourceType=null, $sourceKey=null, $exceptionMsgAppendix)
    {
        $string = preg_replace_callback(
            '/\{resource:([^:\}\{]+?):([^:\}\{]+?)\}/',
            function ($matches) use ($exceptionMsgAppendix, $sourceBlueprint, $sourceType, $sourceKey) {
                try {
                    $this->dependencyTracker->trackStackDependency('resource', $matches[1], $matches[2], $sourceBlueprint, $sourceType, $sourceKey);
                    return $this->getStackFactory($sourceBlueprint)->getStackResource($matches[1], $matches[2]);
                } catch (StackNotFoundException $e) {
                    throw new \Exception("Error resolving '{$matches[0]}'$exceptionMsgAppendix", 0, $e);
                } catch (CloudFormationException $e) {
                    $extractedMessage = Helper::extractMessage($e);
                    throw new \Exception("Error resolving '{$matches[0]}'$exceptionMsgAppendix (CloudFormation error: $extractedMessage)");
                }
            },
            $string
        );
        return $string;
    }

    /**
     * {parameter:...:...}
     *
     * @param $string
     * @param Blueprint $sourceBlueprint
     * @param $sourceType
     * @param $sourceKey
     * @param $exceptionMsgAppendix
     * @return mixed
     */
    protected function resolveParameter($string, Blueprint $sourceBlueprint=null, $sourceType=null, $sourceKey=null, $exceptionMsgAppendix)
    {
        $string = preg_replace_callback(
            '/\{parameter:([^:\}\{]+?):([^:\}\{]+?)\}/',
            function ($matches) use ($exceptionMsgAppendix, $sourceBlueprint, $sourceType, $sourceKey) {
                try {
                    $this->dependencyTracker->trackStackDependency('parameter', $matches[1], $matches[2], $sourceBlueprint, $sourceType, $sourceKey);
                    return $this->getStackFactory($sourceBlueprint)->getStackParameter($matches[1], $matches[2]);
                } catch (StackNotFoundException $e) {
                    throw new \Exception("Error resolving '{$matches[0]}'$exceptionMsgAppendix", 0, $e);
                } catch (CloudFormationException $e) {
                    $extractedMessage = Helper::extractMessage($e);
                    throw new \Exception("Error resolving '{$matches[0]}'$exceptionMsgAppendix (CloudFormation error: $extractedMessage)");
                }
            },
            $string
        );
        return $string;
    }

    /**
     * {clean:...}
     *
     * @param $string
     * @return mixed
     */
    protected function resolveClean($string)
    {
        $string = preg_replace_callback(
            '/\{clean:([^:\}\{]+?)\}/',
            function ($matches) {
                return preg_replace('/[^-a-zA-Z0-9]/', '', $matches[1]);
            },
            $string
        );
        return $string;
    }

    /**
     * Resolve conditional value
     *
     * @param array $values
     * @param Blueprint|null $sourceBlueprint
     * @return string
     * @throws \Exception
     */
    public function resolveConditionalValue($values, Blueprint $sourceBlueprint=null)
    {
        if (!is_array($values)) {
            return $values;
        }
        foreach ($values as $condition => $value) {
            if ($this->isTrue($condition, $sourceBlueprint)) {
                return $value;
            }
        }
        return '';
    }

    /**
     * {profile:...:...}
     *
     * @param $string
     * @param Blueprint $sourceBlueprint
     * @param $sourceType
     * @param $sourceKey
     * @param $exceptionMsgAppendix
     * @return mixed
     */
    protected function switchProfile($string, Blueprint $sourceBlueprint=null, $sourceType=null, $sourceKey=null, $exceptionMsgAppendix)
    {
        $string = preg_replace_callback(
            '/\[profile:([^:\]\[]+?):([^\]\[]+?)\]/',
            function ($matches) use ($exceptionMsgAppendix, $sourceBlueprint, $sourceType, $sourceKey) {
                try {
                    $profile = $matches[1];
                    $substring = $matches[2];

                    // recursively create another ValueResolver, but this time with a different profile
                    $subValueResolver = new ValueResolver(
                        $this->dependencyTracker,
                        $this->profileManager,
                        $this->config,
                        $profile
                    );
                    return $subValueResolver->resolvePlaceholders($substring, $sourceBlueprint, $sourceType, $sourceKey);
                } catch (StackNotFoundException $e) {
                    throw new \Exception("Error resolving '{$matches[0]}'$exceptionMsgAppendix", 0, $e);
                } catch (CloudFormationException $e) {
                    $extractedMessage = Helper::extractMessage($e);
                    throw new \Exception("Error resolving '{$matches[0]}'$exceptionMsgAppendix (CloudFormation error: $extractedMessage)");
                }
            },
            $string
        );
        return $string;
    }

    protected function getStackFactory(Blueprint $sourceBlueprint=null)
    {
        if (!is_null($this->forceProfile)) {
            return $this->profileManager->getStackFactory($this->forceProfile);
        }
        return $this->profileManager->getStackFactory($sourceBlueprint ? $sourceBlueprint->getProfile() : null);
    }

    /**
     * Evaluate is key 'is true'
     *
     * @param $condition
     * @param Blueprint|null $sourceBlueprint
     * @return bool
     * @throws \Exception
     */
    public function isTrue($condition, Blueprint $sourceBlueprint=null)
    {
        // resolve placeholders
        $condition = $this->resolvePlaceholders($condition, $sourceBlueprint, 'conditional_value', $condition);

        if ($condition == 'default') {
            return true;
        }
        if (strpos($condition, '==') !== false) {
            list($left, $right) = explode('==', $condition, 2);
            $left = trim($left);
            $right = trim($right);
            return ($left == $right);
        } elseif (strpos($condition, '!=') !== false) {
            list($left, $right) = explode('!=', $condition, 2);
            $left = trim($left);
            $right = trim($right);
            return ($left != $right);
        } elseif (strpos($condition, '~=') !== false) {
            list($subject, $pattern) = explode('~=', $condition, 2);
            $subject = trim($subject);
            $pattern = trim($pattern);
            return preg_match($pattern, $subject);
        } else {
            throw new \Exception('Invalid condition: ' . $condition);
        }
    }

    /**
     * Craft exception message appendix
     *
     * @param Blueprint $sourceBlueprint
     * @param $sourceType
     * @param $sourceKey
     * @return array|string
     */
    protected function getExceptionMessageAppendix(Blueprint $sourceBlueprint=null, $sourceType=null, $sourceKey=null)
    {
        $tmp = [];
        if ($sourceBlueprint) { $tmp[] = 'Blueprint: ' . $sourceBlueprint->getName(); }
        if ($sourceType) { $tmp[] = 'Type:' . $sourceType; }
        if ($sourceKey) { $tmp[] = 'Key:' . $sourceKey; }
        if (count($tmp)) {
            return ' (' . implode(', ', $tmp) . ')';
        }
        return '';
    }

}