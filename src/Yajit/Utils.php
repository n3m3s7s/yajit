<?php

namespace Yajit;

class Utils {

    static function log($var, $pre = '') {
        $logfileDir = DOCROOT . "/logs";

        $file = $logfileDir . "/" . date("Y-m-d") . ".log";

        if (is_array($var) OR is_object($var)) {
            $var = print_r($var, 1);
        }
        $line = ($pre == '') ? $var : "$pre: $var";
        $line = "[" . date("Y-m-d H:i:s") . "] - " . $line . PHP_EOL;
        try {
            file_put_contents($file, $line, FILE_APPEND);
        } catch (Exception $ex) {
            
        }
    }

}
