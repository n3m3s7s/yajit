<?php
require 'vendor/autoload.php';
define('WORKSPACE', rtrim(realpath(dirname(__FILE__) ), '/'));

use Yajit\Yajit;

$yajit = new Yajit();
$yajit->process();