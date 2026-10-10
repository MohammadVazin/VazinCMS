from pathlib import Path
import tempfile,subprocess,os,time,json,re,pwd,signal,importlib.util
ROOT=Path(__file__).resolve().parents[2]
SPEC=importlib.util.spec_from_file_location('fpm_status_evidence',ROOT/'scripts/fpm_status_evidence.py')
assert SPEC and SPEC.loader
evidence=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(evidence)
with tempfile.TemporaryDirectory(prefix='vazincms-fpm-status-proof-',dir='/dev/shm') as dirname:
 t=Path(dirname);t.chmod(0o755)
 www=pwd.getpwnam('www-data')
 work=t/'private';work.mkdir(mode=0o700);os.chown(work,www.pw_uid,www.pw_gid)
 php=t/'slow.php'
 php.write_text('<?php file_put_contents("'+str(work/'started')+'","started");sleep(4);file_put_contents("'+str(work/'finished')+'","finished");echo "done";')
 php.chmod(0o644)
 conf=t/'fpm.conf';conf.write_text(f'''[global]
pid={t}/fpm.pid
error_log={t}/fpm.log
daemonize=no
[site]
user=www-data
group=www-data
listen={t}/site.sock
listen.owner=www-data
listen.group=www-data
pm=static
pm.max_children=1
pm.status_path=/__cms_fpm_status
pm.status_listen={t}/status.sock
''')
 configcheck=subprocess.run(['/usr/sbin/php-fpm8.5','-tt','--fpm-config',str(conf)],capture_output=True,text=True,timeout=8)
 print('ISOLATED_FPM_CONFIG_PASS',configcheck.returncode==0,flush=True)
 if configcheck.returncode:raise SystemExit(70)
 server=subprocess.Popen(['/usr/sbin/php-fpm8.5','--nodaemonize','--fpm-config',str(conf)],
   stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 slow=None
 try:
  def fcgi(socket,filename,path,query=''):
   e=dict(os.environ);e.update({'REQUEST_METHOD':'GET','SCRIPT_FILENAME':filename,'SCRIPT_NAME':path,'REQUEST_URI':path,'QUERY_STRING':query,'SERVER_PROTOCOL':'HTTP/1.1','SERVER_SOFTWARE':'isolated-test','SERVER_NAME':'localhost','REMOTE_ADDR':'127.0.0.1','DOCUMENT_ROOT':str(t)})
   return subprocess.run(['/usr/bin/cgi-fcgi','-bind','-connect',str(socket)],env=e,
       capture_output=True,text=True,timeout=9)
  ready=False
  for i in range(35):
   if (t/'status.sock').is_socket():
    try:
     x=fcgi(t/'status.sock','/__cms_fpm_status','/__cms_fpm_status','json')
     obj=json.loads(x.stdout.split('\r\n\r\n',1)[-1] if '\r\n\r\n' in x.stdout else x.stdout.split('\n\n',1)[-1])
     if isinstance(obj,dict):ready=True;break
    except Exception:pass
   time.sleep(.1)
  print('STATUS_SOCKET_READY',ready,flush=True)
  if not ready:
   print('FPM_ERROR_CLASS','status_socket_not_reachable')
   raise SystemExit(70)
  print('IDLE_POOL_ACTIVE_PROCESSES',obj.get('active processes'), 'IDLE_PROCESSES',obj.get('idle processes'),'QUEUE',obj.get('listen queue'))
  env=dict(os.environ);env.update({'REQUEST_METHOD':'GET','SCRIPT_FILENAME':str(php),'SCRIPT_NAME':'/slow.php','REQUEST_URI':'/slow.php','SERVER_PROTOCOL':'HTTP/1.1','SERVER_NAME':'localhost','REMOTE_ADDR':'127.0.0.1','DOCUMENT_ROOT':str(t)})
  slow=subprocess.Popen(['/usr/bin/cgi-fcgi','-bind','-connect',str(t/'site.sock')],env=env,
       stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  for i in range(50):
   if (work/'started').exists():break
   time.sleep(.05)
  print('SIMULATED_REQUEST_STARTED',(work/'started').exists())
  busy=fcgi(t/'status.sock','/__cms_fpm_status','/__cms_fpm_status','json')
  body=busy.stdout.split('\r\n\r\n',1)[-1] if '\r\n\r\n' in busy.stdout else busy.stdout.split('\n\n',1)[-1]
  data=json.loads(body)
  print('WHILE_BUSY_ACTIVE',data.get('active processes'),'WHILE_BUSY_IDLE',data.get('idle processes'),'QUEUE',data.get('listen queue'))
  print('STATUS_SOCKET_WORKED_WHILE_FPM_SITE_WORKER_BUSY',data.get('active processes',0)>=1,flush=True)
  # Live socket status collection must see the active worker and remain NO_GO.
  observed_busy=evidence.sample_series(t/'status.sock','site',3,0.1)
  print('REAL_STATUS_SAMPLER_BUSY_REPORTED',not observed_busy['observed_quiet'])
  print('REAL_STATUS_SAMPLER_ALWAYS_NO_GO',observed_busy['decision']=='NO_GO')
  slow.wait(timeout=8)
  print('SLOW_REQUEST_COMPLETED_AND_WROTE',(work/'finished').exists())
  idle=fcgi(t/'status.sock','/__cms_fpm_status','/__cms_fpm_status','json')
  after=json.loads(idle.stdout.split('\r\n\r\n',1)[-1] if '\r\n\r\n' in idle.stdout else idle.stdout.split('\n\n',1)[-1])
  print('AFTER_ACTIVE',after.get('active processes'),'AFTER_IDLE',after.get('idle processes'))
  observed_idle=evidence.sample_series(t/'status.sock','site',3,0.1)
  print('REAL_STATUS_SAMPLER_IDLE_SEEN',observed_idle['observed_quiet'])
  print('REAL_STATUS_SAMPLER_IDLE_STILL_NO_GO',not observed_idle['release_authorized'])
  assert data.get('active processes',0)>=1 and after.get('active processes')==0
 finally:
  if slow and slow.poll() is None:
   slow.terminate()
   try:slow.wait(timeout=3)
   except subprocess.TimeoutExpired:slow.kill();slow.wait(timeout=3)
  if server.poll() is None:
   server.terminate()
   try:server.wait(timeout=4)
   except subprocess.TimeoutExpired:server.kill();server.wait(timeout=3)
  print('ISOLATED_FPM_STOPPED',server.poll() is not None,flush=True)
print('TEMPORARY_STATUS_FILES_CLEANED',True,flush=True)
