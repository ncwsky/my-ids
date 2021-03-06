#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/conf.php';
require __DIR__ . '/../myphp/base.php';
require __DIR__ . '/../myphp/GetOpt.php';


//解析命令参数
GetOpt::parse('h:n:', ['host:', 'num:']);
$testCount = (int)GetOpt::val('n', 'num', 0);
$host = GetOpt::val('h', 'host', '192.168.0.245:55013');
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
$name = 'id_14';
$cmd = 'a=init&name='.$name.'&init_id=0&delta=1'; //自增数
if($cmd && $client->send($cmd)){
    echo $client->recv().PHP_EOL;
}

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
        $start_id = 0;
        $real_count = count($idList);
        db()->beginTrans();
        foreach ($idList as $id){
            if($start_id==0) $start_id = $id;
            db()->add(['id'=>$id], 'test');
            $count++;
        }
        db()->commit();
        $run_times++;
        $idNum = ($id-$start_id+1);
        echo $run_times.'-> last_id:'.$id.', num:'.$idNum.', real_count:'. $real_count.PHP_EOL;
        $line = $count.','.$idNum.','.$start_id.','.$id.PHP_EOL;
        file_put_contents(__DIR__.'/'.$name.'.csv', $line, FILE_APPEND|LOCK_EX);
    } catch (Exception $e) {
        echo date("Y-m-d H:i:s") . ' err: ' . $e->getMessage(), PHP_EOL;
        db()->rollBack();
        break;
    }
    if ($testCount && $run_times >= $testCount) {
        break;
    }
}

