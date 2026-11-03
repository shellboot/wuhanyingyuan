{
  "apps": [
    {
      "name": "meituan-jump",
      "script": "server.js",
      "cwd": "/opt/meituan-redirect",
      "instances": 1,
      "exec_mode": "fork",
      "autorestart": true,
      "max_memory_restart": "300M",
      "env": {
        "NODE_ENV": "production",
        "PORT": 3000
      },
      "out_file": "/var/log/meituan-jump/out.log",
      "error_file": "/var/log/meituan-jump/error.log",
      "time": true
    }
  ]
}
