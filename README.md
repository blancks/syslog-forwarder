# Syslog Forwarder for FRITZ!Box

A PHP script that forwards FRITZ!Box system logs to a syslog server.

## Background

This project was created to address the lack of native syslog forwarding capabilities in FRITZ!Box routers. It allows centralizing router event logs to a syslog server for better log management and monitoring.

This tool started as a personal utility and was developed in just a few hours. It's being shared for others who might need a simple solution to the same problem.

### Why PHP?

PHP was chosen simply because it's a language I'm comfortable with. While there might be better technical choices for this task, PHP is simple enough to get the job done. The performance impact of using PHP with polling is negligible - even with 1-second refresh intervals, the resource usage remains minimal.

## Features

- Real-time log forwarding from FRITZ!Box to syslog server
- Supports RFC 3164 syslog format
- Configurable refresh interval and retry mechanism
- Secure authentication with FRITZ!Box web interface
- Support for both legacy MD5 and modern PBKDF2 authentication
- Graceful connection handling and error recovery

## Requirements

- PHP 8.2 or higher
- CURL extension
- Access to a FRITZ!Box device
  - Tested with FRITZ!Box 7530
  - Tested with FRITZ!OS v.8.02
- A syslog server (like rsyslog, syslog-ng)

Note: This is a command-line script - no web server is required.

## Installation

1. Clone this repository or download the script
2. Ensure PHP is installed and available in your PATH
3. Make the script executable (Unix systems):
   ```bash
   chmod +x syslog-forwarder.php
   ```

## Configuration

Edit the script and set these constants at the top of the file:

```php
const SYSLOG_SERVER = 'udp://127.0.0.1:514';        // Syslog server endpoint
const SYSLOG_MESSAGE_IDENTIFIER = 'FRITZ!Box';       // Log message prefix
const FRITZBOX_ENDPOINT = 'http://192.168.1.1';     // FRITZ!Box URL
const FRITZBOX_USERNAME = 'admin';                   // FRITZ!Box username
const FRITZBOX_PASSWORD = '';                        // FRITZ!Box password
```

If configuration values are not set, the script will prompt for them at runtime.

## Usage

Run the script from command line:

```bash
php syslog-forwarder.php
```

The script will:
1. Connect to your FRITZ!Box
2. Authenticate using the provided credentials
3. Poll for new log entries at the configured interval
4. Forward logs to your syslog server

To stop the script, press Ctrl+C.

### Running as a Service

To run the script as a systemd service on Linux:

1. Create a service file `/etc/systemd/system/syslog-forwarder.service`:
```ini
[Unit]
Description=Syslog Forwarder for FRITZ!Box
After=network.target

[Service]
Type=simple
User=YOUR_USERNAME
ExecStart=/usr/bin/php /path/to/syslog-forwarder.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

2. Enable and start the service:
```bash
sudo systemctl enable syslog-forwarder
sudo systemctl start syslog-forwarder
```

3. Check status with:
```bash
sudo systemctl status syslog-forwarder
```

### Running as container

Build the container:
```
docker build -t avm-syslog-forwarder .
```

Edit the settings in `env` file.

Run the container using docker:
```
docker run -d --env-file env avm-syslog-forwarder
```

## Syslog Message Format

Messages are sent in RFC 3164 format:
- Facility: User-level messages (1)
- Severity: Notice (5)
- Message format: `FRITZ!Box - (id:{ID}; group:{GROUP}) {MESSAGE}`

## Error Handling

- Network errors will be retried with exponential backoff
- Invalid sessions are automatically renewed
- All errors are logged with timestamps

## Contributing

Feel free to:
- Open issues if you need help
- Request new features
- Submit pull requests to improve the project

All contributions are welcome! This project aims to be a simple solution, but that doesn't mean it can't be improved.

## Trademark Notice

FRITZ!Box™ and FRITZ!OS™ are registered trademarks of AVM Computersysteme Vertriebs GmbH. This project is not affiliated with, endorsed by, or connected to AVM in any way.

## License

This project is open source and available under the MIT License.
