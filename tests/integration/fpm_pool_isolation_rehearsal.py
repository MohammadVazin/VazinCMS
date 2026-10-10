"""Isolated two-host Nginx + two independent PHP-FPM masters; no production access."""
from pathlib import Path
import os
import pwd
import socket
import subprocess
import tempfile
import time

with tempfile.TemporaryDirectory(prefix="vazincms-dual-fpm-", dir="/dev/shm") as base:
    root = Path(base)
    root.chmod(0o755)
    webuser = pwd.getpwnam("www-data")
    site_dir = root/"sites"
    site_dir.mkdir(mode=0o755)
    a = site_dir/"travel"
    b = site_dir/"visa"
    a.mkdir(mode=0o755)
    b.mkdir(mode=0o755)
    marker = root/"marker"
    marker.mkdir(mode=0o700)
    os.chown(marker, webuser.pw_uid, webuser.pw_gid)
    (a/"health.php").write_text('<?php header("Content-Type: application/json"); echo \'{"ok":true,"service":"Travel","version":"1"}\';')
    (b/"health.php").write_text('<?php header("Content-Type: application/json"); echo \'{"ok":true,"service":"Visa","version":"1"}\';')
    (a/"slow.php").write_text(
        "<?php file_put_contents("+repr(str(marker/"started"))+',"started"); '
        "sleep(3); file_put_contents("+repr(str(marker/"finished"))+',"finished"); '
        "echo 'done';"
    )
    for file in (a/"health.php",b/"health.php",a/"slow.php"):
        file.chmod(0o644)
    def freeport():
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            return sock.getsockname()[1]
    https = freeport()
    cert=root/"cert.pem";key=root/"key.pem"
    subprocess.run([
        "openssl","req","-x509","-newkey","rsa:2048","-nodes","-days","1",
        "-keyout",str(key),"-out",str(cert),"-subj","/CN=travel.example.test",
        "-addext","subjectAltName=DNS:travel.example.test,DNS:visa.example.test"],
        check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=12)
    pools=[]
    for name in ("travel","visa"):
        socketpath=root/(name+".sock")
        cfg=root/(name+".conf")
        cfg.write_text(f"""[global]
pid={root}/{name}.pid
error_log={root}/{name}.log
daemonize=no
[{name}]
user=www-data
group=www-data
listen={socketpath}
listen.owner=www-data
listen.group=www-data
listen.mode=0660
pm=static
pm.max_children=1
clear_env=yes
pm.status_path=/__private_fpm_status
pm.status_listen={root}/{name}-status.sock
""")
        checked=subprocess.run(["/usr/sbin/php-fpm8.5","-tt","--fpm-config",str(cfg)],capture_output=True,text=True,timeout=8)
        print("FPM_CONFIG",name,"PASS" if checked.returncode==0 else "FAIL",flush=True)
        if checked.returncode:raise SystemExit(70)
        pools.append((name,socketpath,cfg))
    nginx_config=root/"nginx.conf"
    nginx_config.write_text(f"""user www-data;
worker_processes 1;
pid {root}/nginx.pid;
error_log {root}/nginx.log warn;
events {{ worker_connections 96; }}
http {{
 access_log off;
 server {{
   listen 127.0.0.1:{https} ssl;
   server_name travel.example.test;
   ssl_certificate {cert};
   ssl_certificate_key {key};
   root {a};
   location ~ \\.php$ {{
       include /etc/nginx/fastcgi_params;
       fastcgi_param SCRIPT_FILENAME {a}$fastcgi_script_name;
       fastcgi_pass unix:{root}/travel.sock;
   }}
 }}
 server {{
   listen 127.0.0.1:{https} ssl;
   server_name visa.example.test;
   ssl_certificate {cert};
   ssl_certificate_key {key};
   root {b};
   location ~ \\.php$ {{
       include /etc/nginx/fastcgi_params;
       fastcgi_param SCRIPT_FILENAME {b}$fastcgi_script_name;
       fastcgi_pass unix:{root}/visa.sock;
   }}
 }}
}}
""")
    nx=subprocess.run(["/usr/sbin/nginx","-t","-c",str(nginx_config)],capture_output=True,text=True,timeout=8)
    print("NGINX_CONFIG_TWO_INDEPENDENT_BACKENDS",nx.returncode==0,flush=True)
    if nx.returncode:raise SystemExit(70)
    procs={}
    slow=None
    try:
        for name,_,cfg in pools:
            procs[name]=subprocess.Popen(["/usr/sbin/php-fpm8.5","--nodaemonize","--fpm-config",str(cfg)],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        procs["nginx"]=subprocess.Popen(["/usr/sbin/nginx","-c",str(nginx_config),"-g","daemon off;"],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        def get(host,path,timeout=6):
            args=["curl","--silent","--show-error","--noproxy","*","--cacert",str(cert),
                  "--max-time",str(timeout),"--resolve",f"{host}:{https}:127.0.0.1",
                  "--output","/dev/null","--write-out","%{http_code}",f"https://{host}:{https}/{path}"]
            proc=subprocess.run(args,capture_output=True,text=True,timeout=timeout+1)
            return proc.returncode==0 and proc.stdout=="200"
        for _ in range(40):
            if get("travel.example.test","health.php") and get("visa.example.test","health.php"):
                break
            time.sleep(.12)
        ready=get("travel.example.test","health.php") and get("visa.example.test","health.php")
        print("BOTH_SITES_READY",ready,flush=True)
        if not ready:raise SystemExit(70)
        slow=subprocess.Popen(["curl","--silent","--show-error","--noproxy","*","--cacert",str(cert),
             "--max-time","9","--resolve",f"travel.example.test:{https}:127.0.0.1",
             "--output","/dev/null",f"https://travel.example.test:{https}/slow.php"],
             stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        for _ in range(40):
            if (marker/"started").is_file():
                break
            time.sleep(.05)
        print("TRAVEL_WORKER_BUSY",(marker/"started").is_file(),flush=True)
        scores=[get("visa.example.test","health.php") for _ in range(8)]
        print("VISA_WHILE_TRAVEL_BUSY",sum(scores),"/",len(scores),flush=True)
        procs["travel"].terminate()
        try:procs["travel"].wait(timeout=5)
        except subprocess.TimeoutExpired:procs["travel"].kill();procs["travel"].wait(timeout=3)
        scores2=[get("visa.example.test","health.php") for _ in range(8)]
        print("VISA_AFTER_TRAVEL_FPM_STOP",sum(scores2),"/",len(scores2),flush=True)
        print("SEPARATE_FPM_PROCESSES",procs["visa"].poll() is None and procs["travel"].poll() is not None,flush=True)
        assert len(scores)==sum(scores) and len(scores2)==sum(scores2)
    finally:
        if slow is not None and slow.poll() is None:
            slow.terminate()
            try:slow.wait(timeout=3)
            except subprocess.TimeoutExpired:slow.kill();slow.wait(timeout=3)
        for name,p in reversed(list(procs.items())):
            if p.poll() is None:
                p.terminate()
                try:p.wait(timeout=3)
                except subprocess.TimeoutExpired:p.kill();p.wait(timeout=3)
        print("TEST_ONLY_PROCESSES_TERMINATED",all(p.poll() is not None for p in procs.values()),flush=True)
print("TEST_TMPFS_CLEANED",True,flush=True)
