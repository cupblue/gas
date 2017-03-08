<?php
    
    // 积分墙回调服务
    class JfqActivate
    {       
        private $server = null;
        private $logpath = null;
        public function __construct()
        {
            date_default_timezone_set('Asia/Shanghai');
            error_reporting(E_ALL ^ E_NOTICE);
            $this->setpath(); // 设置日志目录
            $this->setcode(); // 返回代码
            $this->init(); // 初始化
        }

        // 实例化
        public function init()
        {
            $this->server = new swoole_server("127.0.0.1", 9502);

            // 服务参数配置
            $this->server->set([
                'task_worker_num' => 8,  // task进程的数量
                'task_ipc_mode' => 1,
                'log_file' => $this->logpath . 'server/jfq_activate.log',
            ]);

            $this->server->on('Start', [$this, 'onStart']); // 启动事件
            $this->server->on('WorkerStart', [$this, 'onWorkerStart']); // 启动进程事件
            $this->server->on('Receive', [$this, 'onReceive']); // 接收
            $this->server->on('Task', [$this, 'onTask']); // 任务
            $this->server->on('Finish', [$this, 'onFinish']); // 结束
            $this->server->start(); // 启动
        }

        // 启动事件
        public function onStart($server)
        {
            cli_set_process_title("active_jfq"); // 设置进程名,以便热更新
        }

        // 启用数据库连接池
        public function onWorkerStart($server,$worker_id)
        {
            // if ( $worker_id >= $this->server->setting['worker_num'] ) 
            // {
            //     $this->db = new mysqli;
            //     $this->db->connect('dbip', 'user', 'dbpasswd', 'dbname');
            // }
        }

        // 接收数据
        public function onReceive($serv, $fd, $from_id, $receive)
        {
            $data = json_decode($receive, true);
            $this->server->task($data); // 异步任务
            $this->server->close($fd); // 关闭当前服务
        }

        // 任务计划
        public function onTask($server,$task_id,$from_id,$data) 
        {
            $clickinfo = $data['clickinfo'];
            unset($data['clickinfo']);

            $app_id = $data['app_id'];
            $idfa = $data['idfa'];
            $mac = $data['mac'] ? $data['mac'] : $clickinfo['mac'];
            $device_id = $data['device_id'];
            $device_os = $data['device_os'];
            $device_model = $data['device_model'];
            $ip = $data['ip'];
            $sdk_version = $data['sdk_version'];
            $coop_id = $data['coop_id'];
            $activate_timestamp = $data['activate_timestamp'] ? $data['activate_timestamp'] : time() * 1000;
            $create_timestamp = $update_timestamp = time() * 1000;

            $this->db = new mysqli;
            $this->db->connect('dbip', 'user', 'dbpasswd', 'dbname');
            
            // 获取游戏信息
            $sql = "select * from gas_app where app_id = '{$app_id}' and status = 1";
            $appinfo = $this->db->query($sql)->fetch_assoc(); 
            $app_uniq_id = $appinfo['app_uniq_id']; // app_id原始唯一值
            if ( ! $app_uniq_id)
            {
                $log = '202' . ' - ' . json_encode($data); // app_uniq_id不存在
                $this->setlog($log, 2);
                return false;
            }

            // 判断idfa是否激活过
            $activatetb = 'gas_activate_' . $app_uniq_id; // 积分墙激活表
            $sql = "select idfa from `{$activatetb}` where idfa = '{$idfa}' and valid_status = 1";
            $activateinfo = $this->db->query($sql)->fetch_assoc();
            if ($activateinfo)
            {
                $log = '403' . ' - ' . json_encode($data); // 已激活过
                $this->setlog($log, 2);
                return false;
            }

            // 获取渠道及游戏相关信息
            $sql = "select 
                        a.*,c.activate_threshold,c.notify_flag,c.notify_rate 
                    from 
                        gas_app_channel a
                    left join
                        gas_channel c
                    on 
                        a.channel_id = c.channel_id
                    where
                        a.app_id = '{$app_id}' and c.channel_id = '{$clickinfo['channel_id']}' and a.status = 1 and c.status = 1";
            $chaninfo = $this->db->query($sql)->fetch_assoc();

            if (empty($chaninfo))
            {
                $log = '303' . ' - ' . json_encode($data); // 渠道不存在
                $this->setlog($log, 2);
                return false;
            }

            $channel_id = $chaninfo['channel_id'];

            // 阀值判断: 优先取渠道阀值，再去游戏阀值
            $channel_threshold = intval($chaninfo['activate_threshold']);
            $game_threshold = intval($chaninfo['activate_threshold']);
            $threshold = ($activate_timestamp - $clickinfo['click_timestamp']) / 1000; // 激活时间与点击时间差值

            if (($channel_threshold > 0 && $threshold < $channel_threshold) || ($threshold < $game_threshold))
            {
                $sql = "insert into `{$activatetb}`
                            (`app_id`,`channel_id`,`coop_id`,`idfa`,`mac`,`ip`,`ip_overlimit_status`,`activate_timestamp`,`device_os`,`device_model`,`sdk_version`,`notify_status`,`cp_notify_status`,`activate_threshold_status`,`valid_status`,`create_timestamp`,`update_timestamp`,`status`)
                        values
                            ('{$app_id}','{$channel_id}','{$coop_id}','{$idfa}','{$mac}','{$ip}',0 ,'{$activate_timestamp}','{$device_os}','{$device_model}','{$sdk_version}',0,0,0,0,'{$create_timestamp}','', 1)";
                $this->db->query($sql);
                $activate_id = mysqli_insert_id($this->db);
                if ($activate_id)
                {
                    $log = '504' . ' - ' . json_encode($data); // 不通过时间阀值插入成功
                    $this->setlog($log, 2);
                    return false;
                } else {
                    $log = '505' . ' - ' . json_encode($data); // 不通过时间阀值插入失败
                    $this->setlog($log, 2);
                    return false;
                }
            }


            // IP数量限制判断
            $ip_limit = intval($appinfo['ip_limit']);
            if ($ip && $ip_limit)
            {
                $sql = "select activate_id from `{$activatetb}` where ip = '{$ip}' and device_os = '{$data['device_os']}' and valid_status = 1";
                $ipnum = $this->db->query($sql)->num_rows;

                if ($ipnum >= $ip_limit)
                {
                    $sql = "insert into `{$activatetb}`
                                (`app_id`,`channel_id`,`coop_id`,`idfa`,`mac`,`ip`,`ip_overlimit_status`,`activate_timestamp`,`device_os`,`device_model`,`sdk_version`,`notify_status`,`cp_notify_status`,`activate_threshold_status`,`valid_status`,`create_timestamp`,`update_timestamp`,`status`)
                            values
                                ('{$app_id}','{$channel_id}','{$coop_id}','{$idfa}','{$mac}','{$ip}',1 ,'{$activate_timestamp}','{$device_os}','{$device_model}','{$sdk_version}',0,0,1,0,'{$create_timestamp}','', 1)";
                    $this->db->query($sql);
                    $activate_id = mysqli_insert_id($this->db);
                    if ($activate_id)
                    {
                        $log = '506' . ' - ' . json_encode($data); // 不通过IP阀值插入成功
                        $this->setlog($log, 2);
                        return false;
                    } else {
                        $log = '507' . ' - ' . json_encode($data); // 不通过IP阀值插入失败
                        $this->setlog($log, 2);
                        return false;
                    }
                }
            }

            // 机型过滤
            $filter_model = array('iPad','iPod touch');
            if (in_array($device_model, $filter_model))
            {
                $sql = "insert into `{$activatetb}`
                                (`app_id`,`channel_id`,`coop_id`,`idfa`,`mac`,`ip`,`ip_overlimit_status`,`activate_timestamp`,`device_os`,`device_model`,`sdk_version`,`notify_status`,`cp_notify_status`,`activate_threshold_status`,`valid_status`,`create_timestamp`,`update_timestamp`,`status`)
                            values
                                ('{$app_id}','{$channel_id}','{$coop_id}','{$idfa}','{$mac}','{$ip}',0 ,'{$activate_timestamp}','{$device_os}','{$device_model}','{$sdk_version}',0,0,1,0,'{$create_timestamp}','', 1)";
                $this->db->query($sql);
                $activate_id = mysqli_insert_id($this->db);
                if ($activate_id)
                {
                    $log = '510' . ' - ' . json_encode($data); // 机型过滤插入成功
                    $this->setlog($log, 2);
                    return false;
                } else {
                    $log = '511' . ' - ' . json_encode($data); // 机型过滤插入失败
                    $this->setlog($log, 2);
                    return false;
                }
            }


            // 写入激活表
            $sql = "insert into `{$activatetb}`
                        (`app_id`,`channel_id`,`coop_id`,`idfa`,`mac`,`ip`,`ip_overlimit_status`,`activate_timestamp`,`device_os`,`device_model`,`sdk_version`,`notify_status`,`cp_notify_status`,`activate_threshold_status`,`valid_status`,`create_timestamp`,`update_timestamp`,`status`)
                    values
                        ('{$app_id}','{$channel_id}','{$coop_id}','{$idfa}','{$mac}','{$ip}',0 ,'{$activate_timestamp}','{$device_os}','{$device_model}','{$sdk_version}',0,0,1,1,'{$create_timestamp}','', 1)";
            $this->db->query($sql);
            $activate_id = mysqli_insert_id($this->db);
            if ($activate_id)
            {
                $log = $app_id . '\x02' . $channel_id . '\x02' . $coop_id . '\x02' . $idfa . '\x02' . $mac . '\x02' . $device_os . '\x02' . $device_model . '\x02' . $sdk_version . '\x02' . $ip; // 插入激活表成功
                $this->setlog($log, 3);
            } else {
                $log = '503' . ' - ' . json_encode($data); // 插入激活表失败
                $this->setlog($log, 2);
                return false;
            }

            // 合并渠道和请求信息数组
            $channel_activate = array_merge($chaninfo, $data);
            $channel_activate['channel_id'] = $channel_id;

            // 判断是否需要回调渠道，不通知
            $rand = mt_rand(0,100);
            if ($chaninfo['channel_notify_flag'] == 0 || ($chaninfo['channel_notify_flag'] == 2 && $chaninfo['notify_flag'] == 0))
            {
                $cpinfo = $channel_activate;
                $cpinfo['activatetb'] = $activatetb;
                $cpinfo['activate_id'] = $activate_id;
                return $cpinfo; // 不通知，退出判断是否通知CP
            } else if ($rand > intval($chaninfo['notify_rate'])) {
                $cpinfo = $channel_activate;
                $cpinfo['activatetb'] = $activatetb;
                $cpinfo['activate_id'] = $activate_id;
                return $cpinfo; // 回调比例，不通知，退出判断是否通知CP
            }

            // 判断是否动态回调，获取回调地址
            if ($chaninfo['dynamic_callback_flag'] == 1)
            {
                $callbackurl = $clickinfo['dynamic_callbackurl']; // 动态回调
            } else {
                $callbackurl = $chaninfo['channel_notifyurl']; // 固定回调
            }

            // 判断是否回调动态额外参数，获取额外参数
            if ($chaninfo['additionalvars_callback_flag'] == 1)
            {
                $callback_flag = trim($clickinfo['additional_vars'],'&'); // 回调额外参数
            } else {
                $callback_flag = '';
            }

            // 回调固定参数,替换参数            
            $other_keys_str = $chaninfo['other_keys_str'];
            if ($other_keys_str)
            {
                $preg = '/{(.*?)}/i';
                preg_match_all($preg, $other_keys_str, $arr); 
                $search = $arr[1] ? array_unique($arr[1]) : '';
                if ($search)
                {
                    foreach ($search as $k => &$v) 
                    {
                        $replace[$k] = $channel_activate[$v];
                        $v = '{'.$v.'}';
                    }
                    $other_keys_str = str_replace($search, $replace, $other_keys_str);
                }
                $callback_flag .= $callback_flag ? '&' . trim($other_keys_str,'&') : trim($other_keys_str,'&');
            }

            // 外部加密算法或额外功能
            if ($chaninfo['encrypt_flag'] == 1 && $chaninfo['encrypt_method'])
            {
                include(dirname(dirname(__FILE__)).'/encrypt/'.$chaninfo['encrypt_method'].'.php');
            }

            // 拼接关键参数
            $callback_flag = $callback_flag ? $callback_flag . '&' : $callback_flag;
            $callback_flag .= $chaninfo['appid_key'] . '=' . $app_id . '&' . $chaninfo['idfa_key'] . '=' . $idfa . '&' . $chaninfo['mac_key'] . '=' . $mac;
 
            $urlarr = explode('?', $callbackurl);
            if ($urlarr[1])
            {
                $str = trim($urlarr[1],'&');
                $callback_flag = $str . '&' .$callback_flag;
            }
            $callbackurl = $urlarr[0] .'?'. $callback_flag;

            $ctx = stream_context_create(array('http' => array('timeout' => 5)));// 设置一个超时时间，单位为秒
            $gret = file_get_contents($callbackurl, 0, $ctx);
            unset($ctx);


            // 记录渠道返回日志
            $gret = is_array($gret) ? json_encode($gret) : $gret;
            $log = $callbackurl . ' - ' . $gret;
            $this->setlog($log, 4);

            // 更新激活表状态
            $sql = "update `{$activatetb}` set `notify_status` = 1,`update_timestamp`='{$update_timestamp}' where `activate_id` = '{$activate_id}'";
            $uret = $this->db->query($sql);
            if (! $uret)
            {
                $log = '508' . ' - ' . json_encode($data); // 激活通知渠道更新激活表失败
                $this->setlog($log, 2);
                return false;
            }

            // 返回判断是否通知CP激活状态
            $cpinfo = $channel_activate;
            $cpinfo['activatetb'] = $activatetb;
            $cpinfo['activate_id'] = $activate_id;
            return $cpinfo;
        }

        
        // 任务结束处理事件
        public function onFinish($server,$task_id, $data)
        {
            // 通知CP激活信息
            if ($data && $data['cp_notify_flag'] == 1 && $data['cp_activate_notifyurl'])
            {
                $cp_activate_notifyurl = $data['cp_activate_notifyurl'];
                $preg = '/{(.*?)}/i';
                preg_match_all($preg, $cp_activate_notifyurl, $arr); 
                $search = $arr[1] ? array_unique($arr[1]) : '';
                if ($search)
                {
                    foreach ($search as $k => &$v) 
                    {
                        $replace[$k] = $data[$v];
                        $v = '{'.$v.'}';
                    }
                    $cp_activate_notifyurl = str_replace($search, $replace, $cp_activate_notifyurl);
                }

                $ctx = stream_context_create(array('http' => array('timeout' => 5)));// 设置一个超时时间，单位为秒
                $ret = file_get_contents($cp_activate_notifyurl, 0, $ctx);
                unset($ctx);

                // 记录渠道返回日志
                $ret = is_array($ret) ? json_encode($ret) : $ret;
                $log = $cp_activate_notifyurl . ' - ' . $ret;
                $this->setlog($log, 5);

                $sql = "update `{$data['activatetb']}` set `cp_notify_status` = 1 where `activate_id` = '{$data['activate_id']}'";
                $ret = $this->db->query($sql);
                if (! $ret)
                {
                    $log = '509' . ' - ' . json_encode($cpinfo['data']); // 激活通知CP更新激活表失败
                    $this->setlog($log, 2);
                    return false;
                }
            }
        }


        // 日志目录
        public function setpath()
        {
            $this->logpath = dirname(dirname(__FILE__)).'/logs/';
        }

        // 写入日志
        public function setlog($log,$type)
        {
            if (is_array($log))
            {
                $log = json_encode($log);
            }
            
            if ($type == 2) { // 失败日志
                $log = date('Y-m-d H:i:s') . ' - ' . $log . "\n";
                $file  = $this->logpath . 'activate/jfq_fail/' . date('Y-m-d') . '.log';
            } else if ($type == 3) { // 成功日志
                $log = date('Y-m-d H:i:s') . '\x02' . $log . "\n";
                $file  = $this->logpath . 'activate/jfq/' . date('Y-m-d') . '.log';
            } else if ($type == 4) { // 渠道返回状态日志
                $log = date('Y-m-d H:i:s') . ' - ' . $log . "\n";
                $file  = $this->logpath . 'activate/jfq_callback/qd_' . date('Y-m-d') . '.log';
            } else if ($type == 5) { // CP返回状态日志
                $log = date('Y-m-d H:i:s') . ' - ' . $log . "\n";
                $file  = $this->logpath . 'activate/jfq_callback/cp_' . date('Y-m-d') . '.log';
            } else {
                return false;
            }

            file_put_contents($file, $log, FILE_APPEND);
            return true;
        }

        // 统一返回值
        public function setcode()
        {
            $this->code = array(
                                    0 => '成功',

                                    101 => '失败',
                                    102 => '密钥不正确',
                                    103 => '连接服务器失败',

                                    201 => 'app_id为空',
                                    202 => 'app_uniq_id不存在',

                                    301 => '渠道名为空',
                                    302 => '渠道ID不正确',
                                    303 => '渠道不存在',

                                    401 => 'idfa为空',
                                    402 => 'idfa没有点击',
                                    403 => 'idfa已经激活过',

                                    501 => '更新成功',
                                    502 => '更新失败',
                                    503 => '插入失败',
                                    504 => '不通过时间阀值插入成功',
                                    505 => '不通过时间阀值插入失败',
                                    506 => '不通过IP阀值插入成功',
                                    507 => '不通过IP阀值插入失败',
                                    508 => '通知渠道更新激活表失败',
                                    509 => '通知CP更新激活表失败',
                                    510 => '机型过滤插入成功',
                                    511 => '机型过滤插入失败',

                                );

        }

    }


    $server = new JfqActivate();


/*    
    nohup /usr/local/php/bin/php /server/JfqActivate.php 1>/dev/null & 启动后台执行php，忽略标准输出
    ps -ef | grep /server/JfqActivate.php 查看进程
    kill -9 `ps -ef | grep /server/JfqActivate.php | awk '{print $2}'` 停止执行php脚本
*/


