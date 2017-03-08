<?php
    
    // 积分墙点击服务
    class JfqClick
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

        // 初始化实例
        public function init()
        {
            $this->server = new swoole_http_server("0.0.0.0", 9551);

            // 配置参数
            $this->server->set([
                'task_worker_num' => 20, // task进程的数量
                'task_ipc_mode' => 1,
                'log_file' => $this->logpath . 'server/jfq_click.log',
            ]);

            $this->server->on('Start', [$this, 'onStart']); // 启动事件
            $this->server->on('WorkerStart', [$this, 'onWorkerStart']); // 启动进程事件
            $this->server->on('Request', [$this, 'onRequest']); // 接收
            $this->server->on('Task', [$this, 'onTask']); // 任务
            $this->server->on('Finish', [$this, 'onFinish']); // 结束
            $this->server->start(); // 启动
        }

        // 启动事件
        public function onStart($server)
        {
            cli_set_process_title("click_jfq"); // 设置进程名,以便热更新
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
        public function onRequest($request, $response)
        {
            $post = $request->post;
            $get = $request->get;
            $request = ($get && $post) ? array_merge($post, $get) : ($get ? $get : $post);

            $this->setlog($request, 1); // 记录所有请求日志

            // app_id 为空
            if ( ! $request['app_id'])
            {
                $log = '201' . ' - ' . json_encode($request);
                $this->setlog($log, 2);
                $result = array('state' => 201, 'tips'=>$this->code[201]);
                $response->end(json_encode($result));
                exit;
            }

            // idfa 为空
            if ( ! $request['idfa'])
            {
                $log = '401' . ' - ' . json_encode($request);
                $this->setlog($log, 2);
                $result = array('state' => 401, 'tips' => $this->code[401]);
                $response->end(json_encode($result));
                exit;
            }

            // 渠道名为空
            if ( ! $request['channel'])
            {
                $log = '301' . ' - ' . json_encode($request);
                $this->setlog($log, 2);
                $result = array('state' => 301, 'tips'=>$this->code[301]);
                $response->end(json_encode($result));
                exit;
            }

            $this->server->task(json_encode($request)); // 异步任务

            $result = array('state' => 0, 'tips' => $this->code[0]);
            $response->end(json_encode($result));
        }

        // 任务计划
        public function onTask($server,$task_id,$from_id, $request) 
        {
            $request = json_decode($request,true);
            $app_id = $request['app_id'];
            $channel = $request['channel'];
            $idfa = strtoupper($request['idfa']);
            $mac = $request['mac'];
            $ip = $request['ip'];
            $channel_id = $request['channel_id'];
            $validate_key = $request['validate_key'];

            $this->db = new mysqli;
            $this->db->connect('dbip', 'user', 'dbpasswd', 'dbname');

            // 获取游戏渠道信息
            $chaninfo = array();

            $sql = "select 
                        a.*,c.channel_name_en
                    from 
                        gas_app_channel a
                    left join
                        gas_channel c
                    on 
                        a.channel_id = c.channel_id
                    where
                        a.status=1 and c.status=1 and c.channel_name_en='{$channel}' and a.app_id='{$app_id}'";
            
            $result = $this->db->query($sql);
            $chaninfo = $result->fetch_assoc();

            // 渠道方有传channel_id,则判断channel_id的有效性
            if ($channel_id && $chaninfo['channel_id'] != $channel_id)
            {

                $log = '302' . ' - ' . json_encode($request); // 渠道ID错误
                $this->setlog($log, 2);
                return false;
            } else {
                $channel_id = $chaninfo['channel_id'];
            }

            // 验证密钥
            if ($chaninfo['validate_key'] && $chaninfo['validate_key'] != $validate_key)
            {
                $log = '102' . ' - ' . json_encode($request); // 密钥不正确
                $this->setlog($log, 2);
                return false;
            }

            // 获取唯一的app_id
            $sql = "select app_uniq_id from gas_app where app_id = '{$app_id}' and status = 1";
            $appinfo = $this->db->query($sql)->fetch_assoc();
            $app_uniq_id = $appinfo['app_uniq_id'];
            if ( ! $app_uniq_id)
            {
                $log = '202' . ' - ' . json_encode($request); // app_uniq_id不存在
                $this->setlog($log, 2);
                return false;
            }

            // 获取动态请求地址
            $dynamic_callbackurl = $request['callback'] ? urldecode($request['callback']) : ''; 

            // 获取额外参数
            $callback = $request;
            unset($callback['app_id']);
            unset($callback['channel_id']);
            unset($callback['channel']);
            unset($callback['idfa']);
            unset($callback['mac']);
            unset($callback['ip']);
            unset($callback['callback']);
            unset($callback['click_time']);
            unset($callback['validate_key']);
            $additional_vars = http_build_query($callback); // 请求连接额外参数

            // 点击时间转化13位时间戳
            if (is_numeric($request['click_time']))
            {
                $click_time = strtotime(date('Y-m-d H:i:s',substr($request['click_time'], 0, 10)));
            } else {
                $click_time = strtotime($request['click_time']);
            }
            $click_time = ($click_time > 1461403085) ? $click_time * 1000 : time() * 1000;

            $clicktb = 'gas_click_' . $app_id; // 点击表
            $activetb = 'gas_activate_' . $app_uniq_id; // 激活表

            // 查询点击表，idfa是否点击过
            $sql = "select click_id from `{$clicktb}` where idfa = '{$idfa}'";
            $isclick = $this->db->query($sql)->num_rows;

            if ($isclick) // 点击过判断
            {
                // 查询idfa是否激活过
                $sql = "select activate_id from `{$activetb}` where idfa = '{$idfa}' and valid_status = 1";
                $isactivate = $this->db->query($sql)->num_rows;

                if ($isactivate)
                {
                    $log = '403' . ' - ' . json_encode($request); // 激活过
                    $this->setlog($log, 2);
                    return false;
                } else {
                    $update_time = time() * 1000;
                    $sql = "update 
                                `{$clicktb}` 
                            set
                                `channel_id` = '{$channel_id}',
                                `ip` = '{$ip}',
                                `mac` = '{$mac}',
                                `click_timestamp` = '{$click_time}',
                                `dynamic_callbackurl` = '{$dynamic_callbackurl}',
                                `additional_vars` = '{$additional_vars}',
                                `update_timestamp` = '{$update_time}',
                                `status` = 1
                            where 
                                `idfa` = '{$idfa}'";

                    $ret = $this->db->query($sql);
                    if ($ret)
                    {
                        $log = '501' . ' - ' . json_encode($request); // 更新成功
                        $this->setlog($log, 2);
                        return false;
                    } else {
                        $log = '502' . ' - ' . json_encode($request); // 更新失败
                        $this->setlog($log, 2);
                        return false;
                    }
                }

            } else { // 没点击过插入
                $create_time = time() * 1000;
                $sql = "insert into `{$clicktb}` 
                            (`app_id`,`channel_id`,`idfa`,`mac`,`ip`,`click_timestamp`,`device_os`,`dynamic_callbackurl`,`additional_vars`,`create_timestamp`,`update_timestamp`,`status`)
                        values
                            ('{$app_id}','{$channel_id}','{$idfa}','{$mac}','{$ip}','{$click_time}','{$chaninfo['plat']}','{$dynamic_callbackurl}','{$additional_vars}','{$create_time}','',1)";

                $ret = $this->db->query($sql);

                if ($ret)
                {
                    $log =  $app_id . '\x02' . $channel_id . '\x02' . $idfa . '\x02' .
                            $mac . '\x02' . $ip . '\x02' . $click_time . '\x02' .
                            $chaninfo['plat']; 
                    // 插入成功 
                    $this->setlog($log, 3); // 成功日志 \x02 分割

                    // 给cp点击信息
                    if ($chaninfo['cp_notify_flag'] == 1 && $chaninfo['cp_click_notifyurl'])
                    {
                        return array_merge($request, $chaninfo);
                    }
                    return true;
                } else {
                    $log = '503' . ' - ' . json_encode($request); // 插入失败
                    $this->setlog($log, 2);
                    return false;
                }
            }
        }

         // 任务结束回调事件
        public function onFinish($server,$task_id, $data)
        {
            // 给cp点击信息
            if ($data && $data['cp_notify_flag'] == 1) 
            {
                $url = $data['cp_click_notifyurl'];
                $preg = '/{(.*?)}/i';
                preg_match_all($preg, $url, $arr); 
                $search = $arr[1] ? array_unique($arr[1]) : '';
                if ($search)
                {
                    foreach ($search as $k => &$v) 
                    {
                        $replace[$k] = $data[$v];
                        $v = '{'.$v.'}';
                    }
                    $url = str_replace($search, $replace, $url);
                }
                $ctx = stream_context_create(array('http' => array('timeout' => 5)));// 设置一个超时时间，单位为秒
                $ret = file_get_contents($url, 0, $ctx);
                unset($ctx);
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
            if ($type == 1) // 汇总日志
            {
                $log = date('Y-m-d H:i:s') . ' - ' . $log . "\n";
                $file = $this->logpath . 'click/jfq_all/' . date('Y-m-d') . '.log';
            } else if ($type == 2) {
                $log = date('Y-m-d H:i:s') . ' - ' . $log . "\n";
                $file  = $this->logpath . 'click/jfq_fail/' . date('Y-m-d') . '.log';
            } else if ($type == 3) {
                $log = date('Y-m-d H:i:s') . '\x02' . $log . "\n";
                $file  = $this->logpath . 'click/jfq/' . date('Y-m-d') . '.log';
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

                                );

        }








    }


    $server = new JfqClick();

/*    
    nohup /usr/local/php/bin/php /server/JfqClick.php 1>/dev/null & 启动后台执行php，忽略标准输出
    ps -ef | grep /server/JfqClick.php 查看进程
    kill -9 `ps -ef | grep /server/JfqClick.php | awk '{print $2}'` 停止执行php脚本
*/



