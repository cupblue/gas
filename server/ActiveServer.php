<?php
    
    // 激活服务总入口
    class ActiveServer
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
            $this->server = new swoole_http_server("0.0.0.0", 9501);

            // 服务参数配置
            $this->server->set([
                'task_worker_num' => 8,  // task进程的数量
                'task_ipc_mode' => 1 ,
                'log_file' => $this->logpath . 'server/activate.log',
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
            cli_set_process_title("active_master"); // 设置进程名,以便热更新
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
            $get = json_decode($get['data'],true);
            $request = ($get && $post) ? array_merge($post, $get) : ($get ? $get : $post);
            $this->setlog($request, 1); // 记录所有请求日志

            $data['app_id'] = $request['app_id'];
            $data['coop_id'] = $request['coop_id']; // 正版渠道
            $data['channel_id'] = $request['channel_id'];
            $data['mac'] = $request['mac'];
            $data['idfa'] = $request['idfa'];
            $data['device_id'] = $request['device_id']; // 分配的设备ID
            $data['device_os'] = $request['device_os']; // 1.ios，2.android
            $data['device_model'] = $request['device_model']; // 机型
            $data['ip'] = $request['ip']; 
            $data['activate_timestamp'] = $request['activate_timestamp']; // 激活时间，毫秒
            $data['sdk_version'] = $request['sdk_version']; // sdk版本
            $data['validate_key'] = $request['validate_key'];

            // app_id 为空
            if ( ! $data['app_id'])
            {
                $log = '201' . ' - ' . json_encode($data);
                $this->setlog($log, 2);
                $result = array('state' => 201, 'tips' => $this->code[201], 'timestamp' => time()*1000);
                $response->end(json_encode($result));
                exit;
            }

            // idfa 为空
            if ( ! $data['idfa'])
            {
                $log = '401' . ' - ' . json_encode($data);
                $this->setlog($log, 2);
                $result = array('state' => 401, 'tips' => $this->code[401], 'timestamp' => time()*1000);
                $response->end(json_encode($result));
                exit;
            }

            // 密钥验证
            $validate_key = 'secrectkey';

            if ($data['validate_key'] != $validate_key)
            {
                $log = '102' . ' - ' . json_encode($data);
                $this->setlog($log, 2);
                $result = array('state' => 102, 'tips' => $this->code[102], 'timestamp' => time()*1000);
                $response->end(json_encode($result));
                exit;
            }

            $this->server->task(json_encode($data)); // 异步任务

            $result = array('state' => 0, 'tips' => $this->code[0], 'timestamp' => time()*1000);
            $response->end(json_encode($result));
        }


        // 任务计划
        public function onTask($server,$task_id,$from_id, $data) 
        {
            $data = json_decode($data,true);
            $app_id = $data['app_id'];
            $idfa = $data['idfa'];

            $this->db = new mysqli;
            $this->db->connect('dbip', 'user', 'dbpasswd', 'dbname');

            // 判断app是否存在
            $sql = "select * from gas_app where app_id = '{$app_id}' and status = 1";
            $appinfo = $this->db->query($sql)->fetch_assoc();
            if ($appinfo)
            {
                // 查询积分墙点击表，判断idfa是否点击过
                $clicktb = 'gas_click_' . $app_id; // 积分墙点击表
                $sql = "select channel_id,click_timestamp,dynamic_callbackurl,additional_vars from `{$clicktb}` where idfa = '{$idfa}'";
                $clickinfo = $this->db->query($sql)->fetch_assoc();
                if ($clickinfo)
                {
                    $data['clickinfo'] = $clickinfo;
                    $jfq = new swoole_client(SWOOLE_SOCK_TCP);
                    $isconnect = @$jfq->connect('127.0.0.1', 9502, 1);  // 连接积分墙服务端
                    if ( ! $isconnect ||  ! $jfq->isConnected()) // 连接错误
                    {
                        $log = '103' . ' - jfq - ' . json_encode($data);
                        $this->setlog($log, 2);
                        return false;
                    }
                    $jfq->send(json_encode($data)); // 发送数据
                    $jfq->close();
                    return true;
                }
            }



            // 查询广点通点击表，判断idfa是否点击过
            $clicktb = 'gdt_click_' . $app_id; // 广点通点击表
            $muid = md5(strtoupper($idfa));
            $sql = "select * from `{$clicktb}` where muid = '{$muid}'";
            $clickinfo = $this->db->query($sql)->fetch_assoc();

            if ($clickinfo)
            {
                $data['clickinfo'] = $clickinfo;
                $gdt = new swoole_client(SWOOLE_SOCK_TCP);
                $isconnect = @$gdt->connect('127.0.0.1', 9503, 1);  // 连接广点通服务端
                if ( ! $isconnect ||  ! $gdt->isConnected()) // 连接错误
                {
                    $log = '103' . ' - gdt - ' . json_encode($data);
                    $this->setlog($log, 2);
                    return false;
                }
                $gdt->send(json_encode($data)); // 发送数据
                $gdt->close();
                return true;
            }

            $log = '402' . ' - ' . json_encode($data);
            $this->setlog($log, 2);

            return false;
        }

        
        // 任务结束处理事件
        public function onFinish($server,$task_id, $data)
        {
           
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
                $file = $this->logpath . 'activate/all/' . date('Y-m-d') . '.log';
            } else if ($type == 2) { // 
                $log = date('Y-m-d H:i:s') . ' - ' . $log . "\n";
                $file  = $this->logpath . 'activate/all_fail/' . date('Y-m-d') . '.log';
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
    $server = new ActiveServer();

/*    
    nohup /usr/local/php/bin/php /server/ActiveServer.php 1>/dev/null & 启动后台执行php，忽略标准输出
    ps -ef | grep /server/ActiveServer.php 查看进程
    kill -9 `ps -ef | grep /server/ActiveServer.php | awk '{print $2}'` 停止执行php脚本
*/


