#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/conf.php';
require __DIR__ . '/../myphp/base.php';
require __DIR__ . '/../myphp/GetOpt.php';

//解析命令参数
GetOpt::parse('h:c:n:', ['host:', 'cmd:', 'name:']);
$cmd =GetOpt::val('c', 'cmd');
$name =GetOpt::val('n', 'name');
$host = GetOpt::val('h', 'host', '192.168.0.245:55012');

$client = TcpClient::instance('', $host);
//$client->type = 'udp';
$client->onInput = function ($buffer) {
    //return MQPackN2::toEncode($buffer) . "\n";
    return \MyId\IdPackN2::input($buffer);
};
$client->onEncode = function ($buffer) {
    //return MQPackN2::toEncode($buffer) . "\n";
    return \MyId\IdPackN2::toEncode($buffer);
};
$client->onDecode = function ($buffer) {
    //$buffer = rtrim($buffer, "\n");
    return substr($buffer, 6);
};
$cmd = 'a=init&name=abc&init_id=0&delta=1'; //自增数
$cmd = 'a=init&name=abc&init_id=-1&delta=2'; //奇数
#$cmd = 'a=init&name=abc&init_id=0&delta=2'; //偶数数

$cmd && $client->send($cmd);
while (1) {
    try {
        $client->send('a=info');
        $ret = $client->recv();
        echo $ret, PHP_EOL;
    } catch (Exception $e) {
        echo $e->getMessage().PHP_EOL;
        sleep(1);
    }
    sleep(1);
}

