<?php
namespace MyId;

/**
 * id自增 - 用于本机服务 自增最大值32|64位有符号
 * Class IdServer
 * @package MyId
 */
class IdServer
{
    use IdMsg;

    //错误提示设置或读取
    public static function err($msg=null, $code=1){
        if ($msg === null) {
            return self::$myMsg;
        } else {
            self::msg('-'.$msg, $code);
        }
    }

    const MAX_UNSIGNED_INT = 4294967295;
    const MAX_INT = 2147483647;
    const MAX_UNSIGNED_BIG_INT = 18446744073709551615;
    const MAX_BIG_INT = 9223372036854775807;

    const ALLOW_ID_NUM = 256; //允许的id数量
    const DEF_STEP = 5000; //默认步长
    const MIN_STEP = 1000; //最小步长
    const PRE_LOAD_RATE = 0.2; //下一段id预载比率
    /**
     * id列表记录  ['name'=>[init_id,max_id,step,delta], ...]
     * @var array
     */
    protected static $idList = [];

    protected static $isChange = false;
    /**
     * 信息统计
     * @var array
     */
    protected static $infoStats = [];

    /**
     * 每秒实时接收数量
     * @var int
     */
    protected static $realRecvNum = 0;

    /**
     * 统计信息 存储
     */
    public static function info(){
        //todo 多进程信息采集
        static::$infoStats['date'] = date("Y-m-d H:i:s", time());
        static::$infoStats['real_recv_num'] = static::$realRecvNum;
        static::$infoStats['id_list'] = static::$idList;
        return IdLib::toJson(static::$infoStats);
    }

    /**
     * 进程启动时处理
     * @param $worker
     * @param $worker_id
     * @throws \Exception
     */
    public static function onWorkerStart($worker, $worker_id)
    {
        IdLib::initConf();

        $idList = db()->table('id_list')->fields('name')->all();
        foreach ($idList as $v) {
            static::toLoadId($v['name']);
        }

        //n ms实时数据落地
/*        $worker->tick(1000, function () use($worker_id){
            static::writeToDisk($worker_id);
        });*/
    }

    /**
     * 终端数据进程结束时的处理
     * @param $worker
     * @param $worker_id
     * @throws \Exception
     */
    public static function onWorkerStop($worker, $worker_id)
    {

    }

    /**
     * 处理数据
     * @param $con
     * @param string $recv
     * @param int|array $fd
     * @return bool|array
     * @throws \Exception
     */
    public static function onReceive($con, $recv, $fd=0)
    {
        static::$realRecvNum++;

        if(strncmp($recv, 'GET', 3)===0){
            $url = substr($recv, 4, strpos($recv, ' ', 4) - 4);
            if (!$url) {
                static::err('URL read failed');
                return false;
            }
            return static::httpGetHandle($con, $url, $fd);
        }

        \SrvBase::$isConsole && \SrvBase::safeEcho($recv . PHP_EOL);
        \Log::trace($recv);

        return static::handle($con, $recv, $fd);
    }

    protected static function handle($con, $recv, $fd=0){
        //认证处理
        $authRet = IdLib::auth($con, $fd, $recv);
        if (!$authRet) {
            //static::err(IdLib::err());
            IdLib::toClose($con, $fd, IdLib::err());
            return '';
        }
        if($authRet==='ok'){
            return 'ok';
        }

        if ($recv[0] == '{') { // substr($recv, 0, 1) == '{' && substr($recv, -1) == '}'
            $data = json_decode($recv, true);
        } else { // querystring
            parse_str($recv, $data);
        }

        if (empty($data)) {
            static::err('empty data: '.$recv);
            return false;
        }
        if (!isset($data['a'])) $data['a'] = 'id';

        $ret = 'ok'; //默认返回信息
        switch ($data['a']) {
            case 'id': //入列 用于消息重试
                $ret = static::nextId($data);
                break;
            case 'init':
                $ret = static::initId($data);
                break;
            case 'info':
                $ret = static::info();
                break;
            default:
                self::err('invalid request');
                $ret = false;
        }
        return $ret;
    }

    /**
     * @param \Workerman\Connection\TcpConnection|\swoole_server $con
     * @param $url
     * @param int $fd
     * @return string
     * @throws \Exception
     */
    protected static function httpGetHandle($con, $url, $fd=0){
        $parse = parse_url($url);
        $data = [];
        $path = $parse['path'];
        if(!empty($parse['query'])){
            parse_str($parse['query'], $data);
        }

        //认证处理
        if (!IdLib::auth($con, $fd, $data['key']??'nil')) {
            static::err(IdLib::err());
            return static::httpSend($con, $fd, false);
        }

        $ret = 'ok'; //默认返回信息
        switch ($path) {
            case '/id': //入列 用于消息重试
                $ret = static::nextId($data);
                break;
            case '/init':
                $ret = static::initId($data);
                break;
            case '/info':
                $ret = static::info();
                break;
            default:
                self::err('Invalid Request');
                $ret = false;
        }

        return static::httpSend($con, $fd, $ret);
    }
    protected static function httpSend($con, $fd, $ret){
        $code = 200;
        $reason = 'OK';
        if ($ret === false) {
            $code = 400;
            $ret = self::err();
            $reason = 'Bad Request';
        }

        $body_len = strlen($ret);
        $out = "HTTP/1.1 {$code} $reason\r\nServer: my-id\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: {$body_len}\r\nConnection: keep-alive\r\n\r\n{$ret}";
        if (\SrvBase::$instance->isWorkerMan) {
            $con->close($out);
        } else {
            $con->send($fd, \MyId\IdPackEof::encode($out));
            $con->close($fd);
        }
        return '';
    }
    /**
     * @param $data
     * @return string|bool
     * @throws \Exception
     */
    protected static function nextId($data){
        if (empty($data['name'])) {
            self::err('Invalid ID name');
            return false;
        }
        $name = strtolower($data['name']);
        if (!isset(static::$idList[$name])) {
            //有新增但其他进程未记录 查询验证并记录
            $one = db()->table('id_list')->fields('name')->where(['name'=>$name])->one();
            if ($one) {
                static::toLoadId($one['name']);
            } else {
                self::err('ID name does not exist');
                return false;
            }
        }
        $size = isset($data['size']) ? (int)$data['size'] : 1;
        if ($size < 2) return static::incrId($name);
        if ($size > static::DEF_STEP) $size = static::DEF_STEP;
        $idRet = '';
        for ($i = 0; $i < $size; $i++) {
            $id = static::incrId($name);
            if ($idRet === '') {
                $idRet = $id;
            } else {
                $idRet .= ',' . $id;
            }
        }
        return $idRet;
    }

    /**
     * 返回自增的id
     * @param $name
     * @return string
     */
    protected static function incrId($name){
        static::$idList[$name]['last_id'] = static::$idList[$name]['last_id'] + static::$idList[$name]['delta'];

        //达到预载条件
        if (static::$idList[$name]['last_id'] > static::$idList[$name]['pro_load_id']) {
            static::toPreLoadId($name);
        }

        //达到本id段最大值 切换到下一已预载的id段 id值并重置为新的
        if (static::$idList[$name]['last_id'] > static::$idList[$name]['max_id']) {
            static::$idList[$name]['max_id'] = static::$idList[$name]['next_max_id'];
            static::$idList[$name]['last_id'] = (static::$idList[$name]['max_id'] - static::$idList[$name]['step']) + static::$idList[$name]['init_id'] + static::$idList[$name]['delta'];
        }

        return (string)static::$idList[$name]['last_id'];
    }

    /**
     * 预载下一段id
     * @param $name
     */
    protected static function toPreLoadId($name)
    {
        db()->beginTrans();
        $info = db()->table('id_list')->where(['name'=>$name])->lock()->one();
        //id下一段预载规则记录
        $pro_load_id = $info['max_id'] + intval(static::PRE_LOAD_RATE * $info['step']);
        //预载下段id最大值
        $next_max_id = $info['max_id'] + $info['step'];
        //更新数据
        db()->update(['max_id' => $next_max_id, 'mtime' => date('Y-m-d H:i:s')], 'id_list', ['id' => $info['id']]);
        db()->commit();
        //echo 'toPreLoadId-before['.$name.']'.json_encode(static::$idList[$name]).PHP_EOL;

        static::$idList[$name]['pro_load_id'] = $pro_load_id;
        static::$idList[$name]['next_max_id'] = $next_max_id;
        //echo 'toPreLoadId-after ['.$name.']'. json_encode(static::$idList[$name]).PHP_EOL;
    }

    /**
     * 加载下一段id
     * @param $name
     */
    protected static function toLoadId($name)
    {
        db()->beginTrans();
        $info = db()->table('id_list')->fields('name,init_id,max_id,step,delta')->where(['name'=>$name])->lock()->one();
        //id起始值
        $info['last_id'] = $info['max_id'] + $info['init_id'];
        //id下一段预载规则记录
        $info['pro_load_id'] = $info['max_id'] + intval(static::PRE_LOAD_RATE * $info['step']);
        //更新max_id
        $info['next_max_id'] = $info['max_id'] = $info['max_id'] + $info['step'];
        //更新数据
        db()->update(['max_id' => $info['max_id'], 'mtime' => date('Y-m-d H:i:s')], 'id_list', ['name'=>$name]);
        db()->commit();
        //echo 'toLoadId['.$name.']:'.json_encode($info).PHP_EOL;

        unset($info['name']);
        static::$idList[$name] = $info;
    }

    /**
     * 初始id信息
     * @param $data
     * @return false|array
     */
    protected static function initId($data){
        $name = isset($data['name']) ? trim($data['name']) : '';
        if (!$name) {
            self::err('Invalid ID name');
            return false;
        }
        $name = strtolower($name);
        if (isset(static::$idList[$name])) {
            self::err('This ID name already exists');
            return false;
        }
        //数据库再次验证
        $info = db()->table('id_list')->fields('id')->where(['name'=>$name])->one();
        if($info){
            self::err('This ID name already exists.');
            return false;
        }

/*
        if (count(static::$idList) >= static::ALLOW_ID_NUM) {
            self::err('已超出可设置id数');
            return false;
        }*/

        $step = isset($data['step']) ? (int)$data['step'] : static::DEF_STEP;
        $delta = isset($data['delta']) ? (int)$data['delta'] : 1;
        $init_id = isset($data['init_id']) ? (int)$data['init_id'] : 0;
        if ($step < static::MIN_STEP) $step = static::DEF_STEP;
        if ($delta < 1) $delta = 1;

        $max_id = $init_id + $step;
        if ($max_id > PHP_INT_MAX) {
            self::err('初始数据无效，已超出最大值限制！');
            return false;
        }

        $data = $info = ['init_id' => $init_id, 'max_id' => $max_id, 'step' => $step, 'delta' => $delta];
        $data['name'] = $name;
        $data['mtime'] = $data['ctime'] = date('Y-m-d H:i:s');

        try{
            db()->add($data, 'id_list');
        } catch (\Exception $e){
            self::err($e->getMessage());
            return false;
        }
        $info['last_id'] = $init_id;
        $info['next_max_id'] = $info['max_id'];
        $info['pro_load_id'] = $init_id + intval(static::PRE_LOAD_RATE * $step);
        static::$idList[$name] = $info;
        return IdLib::toJson(static::$idList[$name]);
    }

    /**
     * 将数据写入磁盘
     * @param $worker_id
     */
    protected static function writeToDisk($worker_id)
    {
        $dir = \SrvBase::$instance->runDir . '/' . \SrvBase::$instance->serverName(). '/stats';
        if(!is_dir($dir)){
            mkdir($dir, 0777, true);
        }
        // 这里可考虑把每个进程的信息记录到相应进程id的文件 用于信息输出
        //file_put_contents($dir . '/' . $worker_id . '.json', '', LOCK_EX | LOCK_NB);
        static::$realRecvNum = 0;

    }
}