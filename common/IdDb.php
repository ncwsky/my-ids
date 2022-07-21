<?php
namespace MyId;


class IdDb implements IdGenerate
{
    const ALLOW_ID_NUM = 256; //允许的id数量
    const DEF_STEP = 100000; //默认步长
    const MIN_STEP = 1000; //最小步长
    const PRE_LOAD_RATE = 0.2; //下一段id预载比率

    /**
     * id列表记录  ['name'=>[init_id,max_id,step,delta], ...]
     * @var array
     */
    protected static $idList = [];

    /**
     * 加载下一段id
     * @param $name
     */
    protected function toLoadId($name)
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
     * 返回自增的id
     * @param $name
     * @return string
     */
    protected function incrId($name){
        static::$idList[$name]['last_id'] = static::$idList[$name]['last_id'] + static::$idList[$name]['delta'];

        //达到预载条件
        if (static::$idList[$name]['last_id'] > static::$idList[$name]['pro_load_id']) {
            $this->toPreLoadId($name);
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
    protected function toPreLoadId($name)
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

    public function init(){
        $lockFile = \SrvBase::$instance->runDir . '/my_id.lock';
        $is_abnormal = file_exists($lockFile);
        touch($lockFile);

        static::$idList = db()->table('id_list')->idx('name')->fields('name,init_id,max_id,step,delta,last_id')->all();
        //更新最大max_id
        foreach (static::$idList as $name => $info) {
            //非正常关闭的 直接使用下一段id
            if($is_abnormal){
                static::$idList[$name]['max_id'] = $info['max_id'] + $info['step'];
                static::$idList[$name]['last_id'] = $info['max_id'];
                //id下一段预载规则记录
                static::$idList[$name]['pro_load_id'] = $info['max_id'] + intval(static::PRE_LOAD_RATE * $info['step']);

                //更新数据
                $last_id = $max_id = static::$idList[$name]['max_id'];
                db()->update(['max_id' => $max_id, 'last_id'=>$last_id, 'mtime' => date('Y-m-d H:i:s')], 'id_list', ['name'=>$name]);

            }
            unset(static::$idList[$name]['name']);
        }
    }
    public function info(){
        return [];
    }
    public function save(){

    }

    public function stop(){
        //正常关闭更新数据最后id
        foreach (static::$idList as $name=>$info){
            db()->update(['last_id'=>$info['last_id'], 'mtime' => date('Y-m-d H:i:s')], 'id_list', ['name'=>$name]);
        }
        $lockFile = \SrvBase::$instance->runDir . '/my_id.lock';
        file_exists($lockFile) && unlink($lockFile);
    }

    /**
     * @param $data
     * @return string|bool
     * @throws \Exception
     */
    public function nextId($data){
        if (empty($data['name'])) {
            IdLib::err('Invalid ID name');
            return false;
        }
        $name = strtolower($data['name']);
        if (!isset(static::$idList[$name])) {
            //有新增但其他进程未记录 查询验证并记录
            $one = db()->table('id_list')->fields('name')->where(['name'=>$name])->one();
            if ($one) {
                static::toLoadId($one['name']);
            } else {
                IdLib::err('ID name does not exist');
                return false;
            }
        }
        $size = isset($data['size']) ? (int)$data['size'] : 1;
        if ($size < 2) return $this->incrId($name);
        if ($size > static::DEF_STEP) $size = static::DEF_STEP;
        $idRet = '';
        for ($i = 0; $i < $size; $i++) {
            $id = $this->incrId($name);
            if ($idRet === '') {
                $idRet = $id;
            } else {
                $idRet .= ',' . $id;
            }
        }
        return $idRet;
    }

    /**
     * 初始id信息
     * @param $data
     * @return false|array
     */
    public function initId($data){
        $name = isset($data['name']) ? trim($data['name']) : '';
        if (!$name) {
            IdLib::err('Invalid ID name');
            return false;
        }
        $name = strtolower($name);
        if (isset(static::$idList[$name])) {
            IdLib::err('This ID name already exists');
            return false;
        }
        //数据库再次验证
        $info = db()->table('id_list')->fields('id')->where(['name'=>$name])->one();
        if($info){
            IdLib::err('This ID name already exists.');
            return false;
        }

/*
        if (count(static::$idList) >= static::ALLOW_ID_NUM) {
            IdLib::err('已超出可设置id数');
            return false;
        }*/

        $step = isset($data['step']) ? (int)$data['step'] : static::DEF_STEP;
        $delta = isset($data['delta']) ? (int)$data['delta'] : 1;
        $init_id = isset($data['init_id']) ? (int)$data['init_id'] : 0;
        if ($step < static::MIN_STEP) $step = static::DEF_STEP;
        if ($delta < 1) $delta = 1;

        $max_id = $init_id + $step;
        if ($max_id > PHP_INT_MAX) {
            IdLib::err('初始数据无效，已超出最大值限制！');
            return false;
        }

        $data = $info = ['init_id' => $init_id, 'max_id' => $max_id, 'step' => $step, 'delta' => $delta];
        $data['name'] = $name;
        $data['mtime'] = $data['ctime'] = date('Y-m-d H:i:s');

        try{
            db()->add($data, 'id_list');
        } catch (\Exception $e){
            IdLib::err($e->getMessage());
            return false;
        }
        $info['last_id'] = $init_id;
        $info['next_max_id'] = $info['max_id'];
        $info['pro_load_id'] = $init_id + intval(static::PRE_LOAD_RATE * $step);
        static::$idList[$name] = $info;
        return IdLib::toJson(static::$idList[$name]);
    }

    /**
     * 更新id信息
     * @param $data
     * @return bool|false|string
     */
    public function updateId($data){
        if (empty($data['name'])) {
            IdLib::err('Invalid ID name');
            return false;
        }
        $name = strtolower($data['name']);
        if (!isset(static::$idList[$name])) {
            //有新增但其他进程未记录 查询验证并记录
            $one = db()->table('id_list')->fields('name')->where(['name'=>$name])->one();
            if ($one) {
                static::toLoadId($one['name']);
            } else {
                IdLib::err('ID name does not exist');
                return false;
            }
        }

        $max_id = 0;
        $step = isset($data['step']) ? (int)$data['step'] : 0;
        $delta = isset($data['delta']) ? (int)$data['delta'] : 0;
        $init_id = isset($data['init_id']) ? (int)$data['init_id'] : 0;
        if ($step < static::MIN_STEP) {
            $step = 0;
        }
        if ($delta < 1) {
            $delta = 0;
        }
        if ($init_id > 0 && $init_id < static::$idList[$name]['last_id']) {
            IdLib::err('Invalid init_id[' . $init_id . ']!');
            return false;
        }

        if ($init_id > 0) {
            $max_id = $init_id + ($step > 0 ? $step : static::$idList[$name]['step']);
            if ($max_id > PHP_INT_MAX) {
                IdLib::err('Invalid max_id['. $max_id .']!');
                return false;
            }
        }


        //todo update
        db()->beginTrans();
        $info = db()->table('id_list')->where(['name'=>$name])->lock()->one();
        //id下一段预载规则记录
        $pro_load_id = $info['max_id'] + intval(static::PRE_LOAD_RATE * $info['step']);
        //预载下段id最大值
        $next_max_id = $info['max_id'] + $info['step'];
        //更新数据
        db()->update(['max_id' => $next_max_id, 'mtime' => date('Y-m-d H:i:s')], 'id_list', ['id' => $info['id']]);
        db()->commit();



        if ($step > 0) {
            static::$idList[$name]['step'] = $step;
            static::$idList[$name]['pre_step'] = intval(static::PRE_LOAD_RATE * $step);
        }
        if ($max_id > 0) static::$idList[$name]['max_id'] = $max_id;
        if ($delta > 0) static::$idList[$name]['delta'] = $delta;
        if ($init_id > 0) {
            static::$idList[$name]['init_id'] = $init_id;
            static::$idList[$name]['last_id'] = $init_id;
            static::$idList[$name]['pro_load_id'] = $init_id + static::$idList[$name]['pre_step'];
        }

        return IdLib::toJson(static::$idList[$name]);
    }
}