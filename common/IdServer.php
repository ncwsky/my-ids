<?php
namespace MyId;

/**
 * id自增 - 用于本机服务 自增最大值32|64位有符号
 * Class IdServerIncr
 * @package MyId
 */
class IdServerIncr
{
    use IdMsg;

    const MAX_UNSIGNED_INT = 4294967295;
    const MAX_INT = 2147483647;
    const MAX_UNSIGNED_BIG_INT = 18446744073709551615;
    const MAX_BIG_INT = 9223372036854775807;

    const DEF_STEP = 1000; //默认步长
    const MIN_STEP = 100; //最小步长
    const PRE_LOAD_RATE = 0.2; //下一段id预载比率
    /**
     * id列表记录  ['name'=>[init_id,max_id,step,delta], ...]
     * @var array
     */
    protected static $idList = [];
    /**
     * id下一段预载规则记录
     * @var array
     */
    protected static $idPreLoadRule = [];

    protected static $isChange = false;
    /**
     * 信息统计
     * @var array
     */
    protected static $infoStats = [];

    /**User
     *
     *
     * 每秒实时接收数量
     * 每秒实时接收数量
     * @var int
     */
    protected static $realRecvNum = 0;

    /**
     * @var int 定时每秒的时间
     */
    public static $tickTime;

    /**
     * 统计信息 存储
     * @param bool $flush
     */
    public static function info(){
        static::$infoStats['date'] = date("Y-m-d H:i:s", static::$tickTime);
        static::$infoStats['real_recv_num'] = static::$realRecvNum;
        static::$infoStats['id_list'] = static::$idList;

        static::$realRecvNum = 0;
    }

    /**
     * 进程启动时处理
     * @param $worker
     * @param $worker_id
     * @throws \Exception
     */
    public static function onWorkerStart($worker)
    {
        ini_set('memory_limit', GetC('memory_limit', '512M'));
        IdLib::initConf();
        $time = time();
        static::$tickTime = $time;

        if(is_file(\SrvBase::$instance->runDir . '/' . \SrvBase::$instance->serverName() . '.json')){
            static::$idList = (array)json_decode(file_get_contents(\SrvBase::$instance->runDir . '/' . \SrvBase::$instance->serverName() . '.json'), true);
            //更新最大max_id
            foreach (static::$idList as $name => $info) {
                static::$idList[$name]['max_id'] = $info['max_id'] + $info['step'];
                static::$idList[$name]['last_id'] = $info['max_id'];
                static::$idList[$name]['pro_load_id'] = static::$idPreLoadRule[$name] = $info['max_id'] + $info['pre_step'];
            }
            static::$isChange = true;
        }

        //n ms实时数据落地
        $worker->tick(1000, function () {
            static::writeToDisk();
        });
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

        if ($recv === '') {
            static::err('nil');
            return false;
        }

        \SrvBase::$isConsole && \SrvBase::safeEcho($recv . PHP_EOL);
        \Log::trace($recv);

        //认证处理
        if (!IdLib::auth($con, $fd, $recv)) {
            static::err(IdLib::err());
            return false;
        }

        return static::handle($con, $recv);
    }

    protected static function handle($con, $recv){
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
                self::err('invalid');
                $ret = false;
        }
        return $ret;
    }

    /**
     * @param $data
     * @return string|bool|int
     * @throws \Exception
     */
    protected static function nextId($data){
        $name = isset($data['name']) ? trim($data['name']) : '';
        if (!$name) {
            self::err('name无效');
            return false;
        }
        $name = strtolower($name);
        if (!isset(static::$idList[$name])) {
            self::err('name不存在');
            return false;
        }
        $size = isset($data['size']) ? (int)$data['size'] : 1;
        if ($size < 2) return static::incrId($name);
        $idRet = '';
        for ($i = 0; $i < $size; $i++) {
            $id = (string)static::incrId($name);
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
     * @return int
     */
    protected static function incrId($name){
        static::$idList[$name]['last_id'] = static::$idList[$name]['last_id'] + static::$idList[$name]['delta'];
        if (static::$idList[$name]['last_id'] > static::$idPreLoadRule[$name]) { //达到预载条件
            static::toPreLoadId($name);
        }
        return static::$idList[$name]['last_id'];
    }

    /**
     * 初始id信息
     * @param $data
     * @return bool
     */
    protected static function initId($data){
        $name = isset($data['name']) ? trim($data['name']) : '';
        if (!$name) {
            self::err('name无效');
            return false;
        }
        $name = strtolower($name);
        if (isset(static::$idList[$name])) {
            self::err('已存在此Id name');
            return false;
        }
        $step = isset($data['step']) ? (int)$data['step'] : static::DEF_STEP;
        $delta = isset($data['delta']) ? (int)$data['delta'] : 1;
        $init_id = isset($data['init_id']) ? (int)$data['init_id'] : 0;
        if ($step < static::MIN_STEP) $step = static::DEF_STEP;
        if ($delta < 1) $delta = 1;
        if ($init_id < 0) $init_id = 0;

        $max_id = $init_id + $step;
        if ($max_id > PHP_INT_MAX) {
            self::err('初始数据无效，已超出最大值限制！');
            return false;
        }

        static::$idList[$name] = ['init_id' => $init_id, 'max_id' => $max_id, 'step' => $step, 'delta' => $delta, 'last_id' => $init_id, 'pre_step'=>intval(static::PRE_LOAD_RATE * $step)];
        static::$idList[$name]['pro_load_id'] = static::$idPreLoadRule[$name] = $init_id + static::$idList[$name]['pre_step'];
        static::$isChange = true;
        static::writeToDisk();
        return static::$idList[$name];
    }

    /**
     * 预载下一段id
     * @param $name
     */
    protected static function toPreLoadId($name)
    {
        static::$idList[$name]['pro_load_id'] = static::$idPreLoadRule[$name] = static::$idList[$name]['max_id'] + static::$idList[$name]['pre_step'];
        static::$idList[$name]['max_id'] = static::$idList[$name]['max_id'] + static::$idList[$name]['step'];
        static::$isChange = true;
    }

    /**
     * 将数据写入磁盘
     */
    protected static function writeToDisk()
    {
        if (!static::$isChange) return;
        file_put_contents(\SrvBase::$instance->runDir . '/' . \SrvBase::$instance->serverName() . '.json', json_encode(static::$idList), LOCK_EX | LOCK_NB);
        static::$isChange = false;
    }
}