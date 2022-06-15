ID生成服务
>全局唯一数字id 最大到9223372036854775807    
>id自增 重启可能存在id浪费  
>支持批量获取id   
>支持生成奇偶的id  
>支付http+tcp接入


单进程ID生成服务 使用json文件记录id数据    
多进程ID生成服务 使用mysql记录id数据 

###安装
    mkdir myid
    
    composer require myphps/mq-id
    
    //单进程ID生成服务 可保持id自增特性 
    cp vendor/myphps/mq-id/run.incr.example.php run.incr.php
    
    //多进程ID生成服务 趋势递增的id，即不保证下一个id一定比上一个大 
    cp vendor/myphps/mq-id/run.example.php run.php
        
    cp vendor/myphps/mq-id/conf.example.php conf.php
    chmod +x run.php
    
    修改 run.php conf.php配置
    运行 ./run.php 
    