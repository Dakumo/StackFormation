<?php

namespace StackFormation\ValueResolver;


class Tstamp extends AbstractValueResolverStage
{

    public function invoke($string)
    {
        static $time;
        if (!isset($time)) {
            $time = time();
        }
        $string = str_replace('{tstamp}', $time, $string);
        return $string;
    }

}
