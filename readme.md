基于workerman的多进程ID生成服务  
>全局唯一数字id 最大到9223372036854775807    
>趋势递增的id，即不保证下一个id一定比上一个大  重启可能存在id浪费  
>支持批量获取id   
>支持生成奇偶的id  
>支付http+tcp接入

###安装   
    mkdir myids
    cd myids
    
    composer require myphps/my-ids
    
    cp vendor/myphps/my-ids/run.example.php run.php
    cp vendor/myphps/my-ids/conf.example.php conf.php
    chmod +x run.php
    
    修改 run.php conf.php配置
    运行 ./run.php 

###使用   
>http模式 

    获取id /id?name=xxx[&size=x]&key=认证key
    初始id /init?name=xxx[&delta=1&step=1000&init_id=0]
>tcp模式  

    获取id 发送 name=xx[&size=x]+"\r\n" 
    初始id 发送 a=init&name=xxx[&delta=1&step=1000&init_id=0]+"\r\n" 