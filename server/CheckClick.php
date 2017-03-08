<?php
    
    // 积分墙排重服务
    class CheckClick
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
            $this->server = new swoole_http_server("0.0.0.0", 9550);

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
            cli_set_process_title("check_click"); // 设置进程名,以便热更新
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

            $app_id = intval($request['app_id']);
            $channel_id = intval($request['channel_id']);
            $idfa = strtoupper($request['idfa']);

            // app_id 为空
            if ( ! $app_id)
            {
                $result = array('state' => 201, 'tips'=>$this->code[201]);
                $response->end(json_encode($result));
                exit;
            }

            // channel_id 为空
            if ( ! $channel_id)
            {
                $result = array('state' => 301, 'tips'=>$this->code[301]);
                $response->end(json_encode($result));
                exit;
            }

            // idfa 为空
            if ( ! $idfa)
            {
                $result = array('state' => 401, 'tips' => $this->code[401]);
                $response->end(json_encode($result));
                exit;
            }

            $this->db = new mysqli;
            $this->db->connect('dbip', 'user', 'dbpasswd', 'dbname');

            $sql = "select app_uniq_id from gas_app where status = 1 and app_id = '{$app_id}'";
            $result = $this->db->query($sql);
            $appinfo = $result->fetch_assoc();
            $app_uniq_id = $appinfo['app_uniq_id'];
            if (! $app_uniq_id)
            {
                $result = array('state' => 202, 'tips'=>$this->code[202]);
                $response->end(json_encode($result));
                exit;
            }

            $table = "gas_activate_" . $app_uniq_id;
            $sql = "select idfa from `{$table}` where idfa = '{$idfa}'";
            $result = $this->db->query($sql);
            $ret = $result->fetch_assoc();

            if (! empty($ret))
            {
                $result = array('state' => 403, 'tips'=>$this->code[403]);
                $response->end(json_encode($result));
                exit;
            }

            $result = array('state' => 0, 'tips' => $this->code[0]);
            $response->end(json_encode($result));
            
         //   $this->server->task(json_encode($request)); // 异步任务
        }

        // 任务计划
        public function onTask($server,$task_id,$from_id, $data) 
        {
     
        }

         // 任务结束回调事件
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
                $file = $this->logpath . 'click/check/' . date('Y-m-d') . '.log';
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


    $server = new CheckClick();

/*    
    nohup /usr/local/php/bin/php /server/CheckClick.php 1>/dev/null & 启动后台执行php，忽略标准输出
    ps -ef | grep /server/CheckClick.php 查看进程
    kill -9 `ps -ef | grep /server/CheckClick.php | awk '{print $2}'` 停止执行php脚本
*/



