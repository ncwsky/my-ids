#!/usr/bin/env php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');// 有些环境关闭了错误显示

if (realpath(dirname($_SERVER['SCRIPT_FILENAME'])) != __DIR__ && !defined('RUN_DIR')) {
    define('RUN_DIR', realpath(dirname($_SERVER['SCRIPT_FILENAME'])));
}

defined('RUN_DIR') || define('RUN_DIR', __DIR__);
if (!defined('VENDOR_DIR')) {
    if (is_dir(__DIR__ . '/vendor')) {
        define('VENDOR_DIR', __DIR__ . '/vendor');
    } elseif (is_dir(__DIR__ . '/../vendor')) {
        define('VENDOR_DIR', __DIR__ . '/../vendor');
    } elseif (is_dir(__DIR__ . '/../../../vendor')) {
        define('VENDOR_DIR', __DIR__ . '/../../../vendor');
    }
}

defined('MY_PHP_DIR') || define('MY_PHP_DIR', VENDOR_DIR . '/myphps/myphp');
//defined('MY_PHP_SRV_DIR') || define('MY_PHP_SRV_DIR', VENDOR_DIR . '/myphps/my-php-srv');

defined('ID_NAME') || define('ID_NAME', 'my_id_incr');
defined('ID_LISTEN') || define('ID_LISTEN', '0.0.0.0');
defined('ID_PORT') || define('ID_PORT', 55012);
defined('IS_SWOOLE') || define('IS_SWOOLE', 0);
defined('MAX_INPUT_SIZE') || define('MAX_INPUT_SIZE', 65536); //接收包限制大小64k

require_once VENDOR_DIR . '/autoload.php';
require_once MY_PHP_DIR . '/GetOpt.php';
defined('MY_PHP_SRV_DIR') && require_once MY_PHP_SRV_DIR . '/Load.php';

//解析命令参数
GetOpt::parse('sp:l:', ['help', 'swoole', 'port:', 'listen:']);
//处理命令参数
$isSwoole = GetOpt::has('s', 'swoole') || IS_SWOOLE;
$port = (int)GetOpt::val('p', 'port', ID_PORT);
$listen = GetOpt::val('l', 'listen', ID_LISTEN);
//自动检测
if (!$isSwoole && !SrvBase::workermanCheck() && defined('SWOOLE_VERSION')) {
    $isSwoole = true;
}

if (GetOpt::has('h', 'help')) {
    echo 'Usage: php MyIdIncr.php OPTION [restart|reload|stop]
   or: MyIdIncr.php OPTION [restart|reload|stop]

   --help
   -l --listen    监听地址 默认 0.0.0.0
   -p --port      tcp端口
   -s --swoole    swolle运行', PHP_EOL;
    exit(0);
}
if(!is_file(RUN_DIR . '/conf.php')){
    echo RUN_DIR . '/conf.php does not exist';
    exit(0);
}

$conf = [
    'name' => ID_NAME, //服务名
    'ip' => $listen,
    'port' => $port,
    'type' => 'tcp', //类型[tcp http]
    'setting' => [ //swooleSrv有兼容处理
        'count' => 1, //单进程模式
        'protocol' => '\MyId\IdPackEof',
        'stdoutFile' => RUN_DIR . '/'.ID_NAME.'.log', //终端输出
        'pidFile' => RUN_DIR . '/.'.ID_NAME.'.pid',  //pid_file
        'logFile' => RUN_DIR . '/'.ID_NAME.'.log', //日志文件 log_file
        'log_level' => 4,
        'open_length_check' => true,
        'package_length_func' => function ($buffer) { //自定义解析长度
            if(substr($buffer, 0, 3)==='GET'){
                $pos = \strpos($buffer, "\r\n\r\n");
                if ($pos === false) {
                    if ($recv_len = \strlen($buffer) >= 16384) { //url接受最大的长度为16384个字符
                        return -1;
                    }
                    return 0;
                }
                return $pos + 4;
            }
            $pos = \strpos($buffer, "\n");
            if ($pos === false) {
                return 0;
            }
            return $pos + 1;
        },
        'package_max_length'=>MAX_INPUT_SIZE, //64k
    ],
    'event' => [
        'onWorkerStart' => function ($worker, $worker_id) {
            \MyId\IdServerIncr::onWorkerStart($worker, $worker_id);
        },
        'onWorkerStop' => function ($worker, $worker_id) {
            \MyId\IdServerIncr::onWorkerStop($worker, $worker_id);
        },
        'onConnect' => function ($con, $fd = 0) use ($isSwoole) {
            if (!$isSwoole) {
                $fd = $con->id;
            }

            if(!\MyId\IdLib::auth($con, $fd)){
                \MyId\IdLib::toClose($con, $fd);
            }
        },
        'onClose' => function ($con, $fd = 0) use ($isSwoole) {
            if (!$isSwoole) {
                $fd = $con->id;
            }
            \MyId\IdLib::auth($con, $fd, false);
            \SrvBase::$isConsole && SrvBase::safeEcho(date("Y-m-d H:i:s ").microtime(true).' onClose '.$fd.PHP_EOL);
        },
        'onReceive' => function (swoole_server $server, int $fd, int $reactor_id, string $data) { //swoole
            $data = \MyId\IdPackEof::decode($data);
            $ret = \MyId\IdServerIncr::onReceive($server, $data, $fd);
            $server->send($fd, \MyId\IdPackEof::encode($ret !== false ? $ret : \MyId\IdServerIncr::err()));
        },
        'onMessage' => function (\Workerman\Connection\ConnectionInterface $connection, $data) { //workerman
            $fd = $connection->id;
            $ret = \MyId\IdServerIncr::onReceive($connection, $data, $fd);
            $connection->send($ret !== false ? $ret : \MyId\IdServerIncr::err());
        },
    ],
    // 进程内加载的文件
    'worker_load' => [
        RUN_DIR . '/conf.php',
        MY_PHP_DIR . '/base.php',
        function () {
            if(__DIR__ != RUN_DIR){
                myphp::class_dir(__DIR__ . '/common');
            }
        }
    ],
];

if ($isSwoole) {
    $srv = new SwooleSrv($conf);
} else {
    // 设置每个连接接收的数据包最大为64K
    \Workerman\Connection\TcpConnection::$defaultMaxPackageSize = MAX_INPUT_SIZE;
    $srv = new WorkerManSrv($conf);
}
$srv->run($argv);