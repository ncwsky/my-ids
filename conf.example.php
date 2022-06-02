<?php
$cfg = array(
    'db' => [
        'dbms' => 'mysql', //数据库
        'server' => '127.0.0.1',//数据库主机
        'name' => 'myid',    //数据库名称
        'user' => 'root',    //数据库用户
        'pwd' => '123456',    //数据库密码
        'port' => 3306,     // 端口
    ],
    'log_dir' => __DIR__ . '/log/', //日志记录主目录名称
    'log_size' => 4194304,// 日志文件大小限制
    'log_level' => 1,// 日志记录等级
    // ----- message queue start -----
    'memory_limit'=>'512M', //内存限制 大量队列堆积会造成内存不足 需要调整限制
    'auth_key' => '', // tcp认证key  发送认证 内容:"#"+auth_key
    'allow_ip' => '', // 允许ip 优先于auth_key
    'alarm_interval' => 60, //重复预警间隔 秒
    'alarm_fail' => 100, //每分钟失败预警值
    'alarm_callback' => function ($type, $value) { // type:waiting|retry|fail, value:对应的触发值
        //todo
        SrvBase::safeEcho($type . ' -> ' . $value . PHP_EOL);
        Log::write($type . ' -> ' . $value, 'alarm');
    },
    // ----- message queue end -----
);