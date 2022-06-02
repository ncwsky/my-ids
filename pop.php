#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/conf.php';
require __DIR__ . '/../myphp/base.php';
require __DIR__ . '/../myphp/GetOpt.php';


//解析命令参数
GetOpt::parse('h:n:', ['host:', 'num:']);
$testCount = (int)GetOpt::val('n', 'num', 0);
$host = GetOpt::val('h', 'host', '192.168.0.245:55012');
if ($testCount <= 0) $testCount = 0;

$client = TcpClient::instance();
$client->config('192.168.0.245:55012');
$client->onInput = function ($buffer) {
    return \MyId\IdPackN2::input($buffer);
};
$client->onEncode = function ($buffer) {
    return \MyId\IdPackN2::toEncode($buffer);
};
$client->onDecode = function ($buffer) {
    return substr($buffer, 6);
};
$count = 0;
while (1) {
    try {
        $client->send('name=test');
        $ret = $client->recv();
        echo date("Y-m-d H:i:s") . ' recv: ' . $ret, PHP_EOL;
        sleep(1);

        $count++;
    } catch (Exception $e) {
        echo date("Y-m-d H:i:s") . ' err: ' . $e->getMessage(), PHP_EOL;
        sleep(2);
    }
    if ($testCount && $count >= $testCount) {
        break;
    }
}

