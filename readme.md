ID生成服务
>全局唯一数字id 最大到9223372036854775807    
>id自增 重启可能存在id浪费  
>支持批量获取id   
>支持生成奇偶的id  
>支付http+tcp接入

TODO    
>多进程增加变更id 通知其他进程同步更新

单进程ID生成服务 使用json文件记录id数据    
多进程ID生成服务 使用mysql记录id数据 使用mysql.sql创建id存储库

###安装
    
    mkdir myid
    cd myid
    
    composer require myphps/my-id
    
    //单进程ID生成服务 可保持id自增特性 
    cp vendor/myphps/my-id/run.incr.example.php run.incr.php
    
    //多进程ID生成服务 趋势递增的id，即不保证下一个id一定比上一个大 
    cp vendor/myphps/my-id/run.example.php run.php
        
    cp vendor/myphps/my-id/conf.example.php conf.php
    chmod +x run.php
    
    修改 run.php conf.php配置
    运行 ./run.php 
    
###使用
>http模式 

    获取id /id?name=xxx[&size=x]
    初始id /init?name=xxx[&delta=1&step=1000&init_id=0]
>tcp模式  

    获取id 发送 name=xx[&size=x]+"\r\n" 
    初始id 发送 a=init&name=xxx[&delta=1&step=1000&init_id=0]+"\r\n" 
