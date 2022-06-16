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
$client->config($host);
$client->packageEof = "\r\n";
$run_times = 0;
//认证
$client->onConnect = function ($client){
    $client->send('123456');
    $client->recv();
};
$name = 'id_incr_1';
$cmd = 'a=init&name='.$name.'&init_id=0&delta=1'; //自增数
if($cmd && $client->send($cmd)){
    echo $client->recv().PHP_EOL;
}
$num = is_file(__DIR__.'/'.$name.'.test') ? (int)file_get_contents(__DIR__.'/'.$name.'.test') : 0;
while (1) {
    try {
        $client->send('name='.$name.'&size='.mt_rand(0, 1000));
        $ret = $client->recv();
        //echo date("Y-m-d H:i:s") . ' recv['.$name.']: ' . $ret, PHP_EOL;
        //sleep(1);
        if(strpos($ret, ',')){
            $idList = explode(',', $ret);
        }else{
            $idList = [$ret];
        }
        $count = 0;
        $real_count = count($idList);
        db()->beginTrans();
        foreach ($idList as $id){
            $num++;
            db()->add(['id'=>$id], 'test_incr');
            $count++;
        }
        db()->commit();
        $run_times++;
        echo $run_times.'-> num:'. $num .', last_id:'.$id.', real_count:'. $real_count.PHP_EOL;
        file_put_contents(__DIR__.'/'.$name.'.test', $num);
    } catch (Exception $e) {
        echo date("Y-m-d H:i:s") . ' err: ' . $e->getMessage(), PHP_EOL;
        db()->rollBack();
        break;
    }
    if ($testCount && $run_times >= $testCount) {
        break;
    }
}

