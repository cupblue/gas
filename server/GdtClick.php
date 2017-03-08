<?php
    
    // 积分墙点击服务
    class GdtClick
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
            $this->server = new swoole_http_server("0.0.0.0", 9552);

            // 配置参数
            $this->server->set([
                'task_worker_num' => 20, // task进程的数量
                'task_ipc_mode' => 1,
                'log_file' => $this->logpath . 'server/gdt_click.log',
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
            cli_set_process_title("click_gdt"); // 设置进程名
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

            // appstore_id 为空
            if ( ! $request['appid'])
            {
                $log = '203' . ' - ' . json_encode($request);
                $this->setlog($log, 2);
                $result = array('state' => 203, 'tips'=>$this->code[203]);
                $response->end(json_encode($result));
                exit;
            }

            // muid 为空
            if ( ! $request['muid'] || strlen($request['muid']) != 32)
            {
                $log = '404' . ' - ' . json_encode($request);
                $this->setlog($log, 2);
                $result = array('state' => 404, 'tips' => $this->code[404]);
                $response->end(json_encode($result));
                exit;
            }

            // app类型 android / ios (小写)
            if ($request['app_type'] != 'android' && $request['app_type'] != 'ios')
            {
                $log = '601' . ' - ' . json_encode($request);
                $this->setlog($log, 2);
                $result = array('state' => 601, 'tips' => $this->code[601]);
                $response->end(json_encode($result));
                exit;
            }

            // 广点通的账户ID
            if ( ! $request['advertiser_id'])
            {
                $log = '602' . ' - ' . json_encode($request);
                $this->setlog($log, 2);
                $result = array('state' => 602, 'tips'=>$this->code[602]);
                $response->end(json_encode($result));
                exit;
            }

            // 广点通生成的点击唯一标识
            if ( ! $request['click_id'])
            {
                $log = '603' . ' - ' . json_encode($request);
                $this->setlog($log, 2);
                $result = array('state' => 603, 'tips'=>$this->code[603]);
                $response->end(json_encode($result));
                exit;
            }

            $this->server->task($request); // 异步任务

            $result = array('ret' => 0, 'msg' => $this->code[0]);
            $response->end(json_encode($result));
        }

        // 任务计划
        public function onTask($server,$task_id,$from_id, $request) 
        {
            $appstore_id = $request['appid']; // 广点通后台配置的应用ID
            $muid = $request['muid'];  // 安卓IMEI号转小写MD5值, IOS IDFA号转大写MD5值
            $app_type = $request['app_type']; // app类型 android / ios (小写)
            $adv_id = $request['advertiser_id']; // 广点通的账户ID
            $click_id = $request['click_id']; // 广点通生成的点击唯一标识
            $click_time = $request['click_time']; // 点击时间戳
            $click_time = ($click_time  && strlen($click_time) == 10) ? $click_time : time();
            
            $this->db = new mysqli;
            $this->db->connect('dbip', 'user', 'dbpasswd', 'dbname');
            

            // 判断appstore_id是否存在关联
            $sql = "select * from gdt_app where appstore_id = '{$appstore_id}' and adv_id = '{$adv_id}' and status = 1";
            $result = $this->db->query($sql);
            $appinfo = $result->fetch_assoc();

            if (empty($appinfo))
            {
                $log = '204' . ' - ' . json_encode($request);
                $this->setlog($log, 2);
                return false;
            }

            // 查询点击表，idfa是否点击过
            $clicktb = 'gdt_click_' . $appinfo['app_id'];
            $sql = "select id from `{$clicktb}` where muid = '{$muid}' and status = 1";
            $isclick = $this->db->query($sql)->num_rows;
            if ( ! $isclick) // 首次点击
            {
                $data['appstore_id'] = $appstore_id;
                $data['muid'] = $muid;
                $data['click_id'] = $click_id;
                $data['adv_id'] = $adv_id;
                $data['app_type'] = $app_type;
                $data['create_timestamp'] = $click_time;
                $data['update_timestamp'] = '';
                $data['click_num'] = 0;
                $data['ip'] = '';
                $data['status'] = 1;

                $sql = "insert into `{$clicktb}` 
                            (`appstore_id`,`muid`,`click_id`,`adv_id`,`app_type`,`create_timestamp`,`update_timestamp`,`click_num`,`ip`,`status`)
                        values
                            ('{$appstore_id}','{$muid}','{$click_id}','{$adv_id}','{$app_type}','{$click_time}','',0,'',1)";
                $ret = $this->db->query($sql);
                if ($ret)
                {
                    $log =  $appinfo['app_id'] . '\x02' . $appstore_id . '\x02' . $adv_id . '\x02' . $muid . '\x02' . $app_type . '\x02' . $click_time; 
                    // 插入成功 
                    $this->setlog($log, 3); // 成功日志 \x02 分割
                    return true;
                } else {
                    $log = '503' . ' - ' . json_encode($request); // 插入失败
                    $this->setlog($log, 2);
                    return false;
                }
            } else {
                $activatetb = 'gdt_activate_'.$appinfo['app_uniq_id'];
                $sql = "select activate_id from `{$activatetb}` where muid = '{$muid}'";
                $isActivated = $this->db->query($sql)->num_rows;         
                if ($isActivated) // 已激活
                {
                    $log = '403' . ' - ' . json_encode($request); // 激活过
                    $this->setlog($log, 2);
                    return false;
                }
                $update_time = time();
                $sql = "update 
                                `{$clicktb}` 
                            set
                                `appstore_id` = '{$appstore_id}',
                                `click_id` = '{$click_id}',
                                `adv_id` = '{$adv_id}',
                                `app_type` = '{$app_type}',
                                `create_timestamp` = '{$click_time}',
                                `update_timestamp` = '{$update_time}',
                                `click_num` = click_num + 1,
                                `status` = 1
                            where 
                                `muid` = '{$muid}'";
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
        }

         // 任务结束回调事件
        public function onFinish($server,$task_id, $data)
        {
            // ...
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
                $file = $this->logpath . 'click/gdt_all/' . date('Y-m-d') . '.log';
            } else if ($type == 2) {
                $log = date('Y-m-d H:i:s') . ' - ' . $log . "\n";
                $file  = $this->logpath . 'click/gdt_fail/' . date('Y-m-d') . '.log';
            } else if ($type == 3) {
                $log = date('Y-m-d H:i:s') . '\x02' . $log . "\n";
                $file  = $this->logpath . 'click/gdt/' . date('Y-m-d') . '.log';
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
                                    203 => 'appstore_id为空',
                                    204 => 'appstore_id不合法',

                                    301 => '渠道名为空',
                                    302 => '渠道ID不正确',
                                    303 => '渠道不存在',

                                    401 => 'idfa为空',
                                    402 => 'idfa没有点击',
                                    403 => 'idfa已经激活过',
                                    404 => 'muid为空',

                                    501 => '更新成功',
                                    502 => '更新失败',
                                    503 => '插入失败',
                                    504 => '不通过时间阀值插入成功',
                                    505 => '不通过时间阀值插入失败',
                                    506 => '不通过IP阀值插入成功',
                                    507 => '不通过IP阀值插入失败',
                                    508 => '通知渠道更新激活表失败',
                                    509 => '通知CP更新激活表失败',
                                    
                                    601 => 'app类型不合法',
                                    602 => '账户不能为空',
                                    603 => 'click_id不能为空',
                                    604 => 'adv_id不能为空',

                                );

        }

    }


    $server = new GdtClick();

/*    
    nohup /usr/local/php/bin/php /server/GdtClick.php 1>/dev/null & 启动后台执行php，忽略标准输出
    ps -ef | grep /server/GdtClick.php 查看进程
    kill -9 `ps -ef | grep /server/GdtClick.php | awk '{print $2}'` 停止执行php脚本
*/



