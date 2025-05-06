#!/usr/bin/env php
<?php
declare(strict_types=1);
setupErrorHandler();

/************************************************************************
 * CONFIGURATION OPTIONS                                                *
 ************************************************************************/

/**
 * Optional - Syslog server endpoint URL
 *
 * URL must include:
 * - Protocol (udp)
 * - Host (IP or hostname)
 * - Port (default: 514)
 *
 * Format: udp://host:port
 * Example: udp://127.0.0.1:514
 *
 * If not provided, will be prompted during script execution.
 */
const SYSLOG_SERVER = '';

/**
 * Optional - Message identifier prefix for syslog entries.
 *
 * This string will be prepended to each message sent to the syslog server.
 * Useful for filtering logs using syslog rules (e.g. $msg contains 'FRITZ!Box')
 *
 * Format: string
 * Example: 'FRITZ!Box'
 */
const SYSLOG_MESSAGE_IDENTIFIER = 'FRITZ!Box';

/**
 * Optional - FRITZ!Box URL endpoint
 *
 * The HTTP URL where your FRITZ!Box web interface is accessible.
 * Do not include a trailing slash.
 *
 * Format: http://host
 * Example: http://192.168.1.1
 *
 * If not provided, will be prompted during script execution.
 */
const FRITZBOX_ENDPOINT = '';

/**
 * Optional - FRITZ!Box administrator username
 *
 * The username used to authenticate with the FRITZ!Box web interface.
 *
 * Format: string
 * Example: 'fritz1234'
 *
 * If not provided, will be prompted during script execution.
 */
const FRITZBOX_USERNAME = '';

/**
 * Optional - FRITZ!Box administrator password
 *
 * The password used to authenticate with the FRITZ!Box web interface.
 * For security reasons, consider leaving this empty to be prompted at runtime.
 *
 * Format: string
 * Example: 'abcdef012345'
 *
 * If not provided, will be prompted securely during script execution.
 */
const FRITZBOX_PASSWORD = '';

/**
 * Required - Refresh interval in seconds
 *
 * The script will poll the FRITZ!Box for new log entries at this interval.
 * Lower values mean more frequent checks but higher system load.
 *
 * Format: integer
 * Example: 5 (checks every 5 seconds)
 * Recommended range: 1-60 seconds
 */
const REFRESH_INTERVAL_SECONDS = 5;

/**
 * Required - Maximum number of retry attempts
 *
 * The number of times the script will retry an operation before giving up.
 * Applies to authentication, log fetching, and syslog sending operations.
 *
 * Format: integer
 * Example: 3 (will try 4 times total - initial attempt plus 3 retries)
 * Minimum value: 0
 */
const MAX_RETRIES_ALLOWED = 3;


/************************************************************************
 * PROGRAM START                                                        *
 ************************************************************************/

// Collect input data

$syslogServerEndpoint = getConfigOrPromptValue('Syslog Server (ex: udp://127.0.0.1:514): ', SYSLOG_SERVER);
$fritzBoxEndpoint = getConfigOrPromptValue('FRITZ!Box URL (ex: http://192.168.1.1): ', FRITZBOX_ENDPOINT);
$fritzBoxUsername = getConfigOrPromptValue('FRITZ!Box Username: ', FRITZBOX_USERNAME);
$fritzBoxPassword = getConfigOrPromptSecure('FRITZ!Box Password: ', FRITZBOX_PASSWORD);

// State variables

$fritzBoxSessionId = null;
$lastLogsTimestamp = time();
$lastRefreshTime = 0;

/************************************************************************
 * MAIN LOOP                                                            *
 ************************************************************************/

while (true) {
    if (time() - $lastRefreshTime < REFRESH_INTERVAL_SECONDS) {
        usleep(500000);
        continue;
    }

    $lastRefreshTime = time();

    // Authentication routine
    if (!isset($fritzBoxSessionId)) {
        try {

            $fritzBoxSessionId = authenticateWithFritzBox(
                endpoint: $fritzBoxEndpoint,
                username: $fritzBoxUsername,
                password: $fritzBoxPassword
            );

        } catch (Throwable $e) {

            stdErr($e->getMessage());
            break;

        }
    }

    // Fetch the updated event logs
    try {

        $eventLogs = fetchEventLogs(
            endpoint: $fritzBoxEndpoint,
            sessionId: $fritzBoxSessionId
        );

        // keep only newer entries
        $eventLogs = array_filter(
            $eventLogs,
            fn($entry) => $entry['timestamp'] > $lastLogsTimestamp
        );

    } catch (Throwable $e) {

        stdErr($e->getMessage());

        if ($e->getCode() === 400) {
            // session invalid/expired -> needs to retry authentication step
            $fritzBoxSessionId = null;
            continue;
        }

        // exit
        break;

    }

    // attempt to push to syslog server
    if (count($eventLogs) !== 0) {
        try {

            $lastLogsTimestamp = syncLogsToSyslog(
                serverEndpoint: $syslogServerEndpoint,
                eventLogs: $eventLogs
            );

        } catch (Throwable $e) {

            stdErr($e->getMessage());
            break;

        }
    }
}

// unexpected exit
exit(1);


/************************************************************************
 * MAIN ACTIONS                                                         *
 ************************************************************************/

/**
 * Authenticates with FRITZ!Box and returns a session ID
 *
 * Makes repeated attempts to authenticate with the FRITZ!Box interface
 * using the provided credentials. If authentication fails, it will retry
 * up to MAX_RETRIES_ALLOWED times with increasing delays between attempts.
 *
 * @param string $endpoint The FRITZ!Box URL (e.g., http://192.168.1.1)
 * @param string $username The administrator username
 * @param string $password The administrator password
 *
 * @throws Exception When authentication fails after maximum retries
 * @return string The session ID for authenticated requests
 */
function authenticateWithFritzBox(
    string $endpoint,
    string $username,
    #[SensitiveParameter]
    string $password
): string {
    $retriesCounter = 0;

    while (true) {
        try {

            stdOut('Attempt to login to FRITZ!Box...', '');

            $sessionId = makeLoginRequest(
                $endpoint,
                $username,
                $password
            );

            stdOut(message: ' Success!', prefix: '');

            return $sessionId;

        } catch (Throwable $e) {

            stdOut(message: ' Fail!', prefix: '');

            if ($e->getCode() === 400) {
                throw $e;
            }

            if (++$retriesCounter > MAX_RETRIES_ALLOWED) {
                throw new Exception(
                    message: sprintf(
                    'Too many login attempt have failed (%d)',
                    $retriesCounter
                    ),
                    previous: $e
                );
            }

            $secondsToWait = $retriesCounter * 5;

            stdOut(sprintf('Waiting for %d seconds before retry.', $secondsToWait));
            sleep($secondsToWait);
            continue;

        }
    }

    throw new Exception('Unknown error');
}

/**
 * Retrieves event logs from FRITZ!Box with retry mechanism
 *
 * Makes repeated attempts to fetch event logs from the FRITZ!Box interface.
 * If the request fails, it will retry up to MAX_RETRIES_ALLOWED times
 * with increasing delays between attempts.
 *
 * @param string $endpoint The FRITZ!Box URL (e.g., http://192.168.1.1)
 * @param string $sessionId Valid session ID from successful authentication
 *
 * @throws Exception When fetching fails after maximum retries
 * @throws Exception With code 400 when session is invalid/expired
 * @return array{
 *   timestamp: int,
 *   date: string,
 *   time: string,
 *   id: int,
 *   group: string,
 *   msg: string,
 *   nohelp: bool
 * } List of event log entries sorted by timestamp
 */
function fetchEventLogs(
    string $endpoint,
    #[SensitiveParameter]
    string $sessionId
): array {
    $retriesCounter = 0;

    while(true) {
        try {

            return makeEventLogsRequest(
                $endpoint,
                $sessionId
            );

        } catch (Throwable $e) {

            stdErr('Unable to fetch syslogs: '. $e->getMessage());

            if ($e->getCode() === 400) {
                throw $e;
            }

            if (++$retriesCounter > MAX_RETRIES_ALLOWED) {
                throw new Exception(
                    message: sprintf(
                    'Too many unexpected failures while fetching eventlogs (%d)',
                    $retriesCounter
                    ),
                    previous: $e
                );
            }

            $secondsToWait = $retriesCounter * 5;

            stdOut(sprintf('Waiting for %d seconds before retry.', $secondsToWait));
            sleep($secondsToWait);
            continue;

        }
    }
}

/**
 * Synchronizes FRITZ!Box event logs to a syslog server with retry mechanism
 *
 * Makes repeated attempts to send event logs to the syslog server.
 * If sending fails, it will retry up to MAX_RETRIES_ALLOWED times
 * with increasing delays between attempts.
 *
 * @param string $serverEndpoint The syslog server URL (e.g., udp://127.0.0.1:514)
 * @param array<int, array{
 *   timestamp: int,
 *   date: string,
 *   time: string,
 *   id: int,
 *   group: string,
 *   msg: string,
 *   nohelp: bool
 * }> $eventLogs Array of log entries to send to syslog server
 *
 * @throws Exception When sending fails after maximum retries
 * @return int Timestamp of the last successfully sent log entry
 */
function syncLogsToSyslog(
    string $serverEndpoint,
    array $eventLogs
): int {
    $retriesCounter = 0;

    while(true) {
        try {

            sendLogsToSyslog($serverEndpoint, $eventLogs);

            // return last entry timestamp
            return end($eventLogs)['timestamp'];

        } catch (Throwable $e) {

            stdErr('Unexpected error when sync logs to syslog server: '. $e->getMessage());

            if (++$retriesCounter > MAX_RETRIES_ALLOWED) {
                throw new Exception(
                    message: sprintf(
                    'Too many unexpected failures while sending logs to syslog server (%d)',
                    $retriesCounter
                    ),
                    previous: $e
                );
            }

            $secondsToWait = $retriesCounter * 5;

            stdOut(sprintf('Waiting for %d seconds before retry.', $secondsToWait));
            sleep($secondsToWait);
            continue;

        }
    }
}


/************************************************************************
 * Input/Output                                                         *
 ************************************************************************/

/**
 * Outputs a message to standard output with timestamp
 *
 * Prepends a timestamp to the message and outputs it to stdout.
 * The timestamp format is 'Y-m-d H:i:s.v' by default.
 *
 * @param string $message The message to output
 * @param string $eol End of line character(s), defaults to PHP_EOL
 * @param string|null $prefix Optional custom prefix, defaults to timestamp
 * @return void
 */
function stdOut(
    string $message,
    string $eol = PHP_EOL,
    ?string $prefix = null
): void {
    if ($prefix === null) {
        $prefix = (new DateTime)->format('Y-m-d H:i:s.v');
    }

    echo $prefix, ' ', $message, $eol;
}

/**
 * Outputs an error message to standard error with timestamp and error level
 *
 * Triggers an error with the specified level and message. The message is
 * prefixed with a timestamp and error level indicator in the format:
 * 'Y-m-d H:i:s.v [LEVEL] message'
 *
 * @param string $message The error message to output
 * @param int $level The error level (E_USER_NOTICE, E_USER_WARNING, E_USER_ERROR)
 * @return void
 * @see trigger_error()
 */
function stdErr(
    string $message,
    int $level = E_USER_WARNING
): void {
    $errorLabel = match($level) {
        E_USER_NOTICE => 'NOTICE',
        E_USER_WARNING => 'WARNING',
        E_USER_ERROR => 'ERROR',
        default => 'UNKNOWN',
    };

    trigger_error(
        message: sprintf(
            '[%s] %s',
            $errorLabel,
            $message
        ),
        error_level: $errorLabel === 'UNKNOWN'
            ? E_USER_WARNING
            : $level
    );
}

/**
 * Sets up custom error handling for the application
 *
 * Configures a custom error handler that formats errors with timestamps
 * and prevents duplicate error messages from the default PHP handler.
 *
 * @return void
 * @see set_error_handler()
 */
function setupErrorHandler(): void
{
    set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        stdOut($errstr);
        return true;
    });
}

/**
 * Returns a configuration value or prompts for user input
 *
 * If a default value is provided, returns it. Otherwise prompts
 * the user for input using the provided prompt message.
 *
 * @param string $prompt The prompt message to display
 * @param string $default Optional default value from configuration
 * @return string The configuration value or user input
 */
function getConfigOrPromptValue(
    string $prompt,
    string $default = ''
): string {
    if ($default !== '') {
        return $default;
    }

    return readline($prompt);
}

/**
 * Returns a configuration value or prompts securely for user input
 *
 * If a default value is provided, returns it. Otherwise prompts
 * the user for input using a secure prompt that hides the input.
 *
 * @param string $prompt The prompt message to display
 * @param string $default Optional default value from configuration
 * @return string The configuration value or secure user input
 */
function getConfigOrPromptSecure(
    string $prompt,
    #[SensitiveParameter]
    string $default = ''
): string {
    if ($default !== '') {
        return $default;
    }

    return readlineSecure($prompt);
}

/**
 * Prompts for password input without displaying characters in console
 *
 * Uses different methods based on the operating system:
 * - Windows: Creates temporary VBScript to show password input dialog
 * - Unix: Uses bash read command with -s flag for secure input
 *
 * @param string $prompt The message to display when asking for password
 * @throws Exception When bash is not available on Unix systems
 * @return string The password entered by user
 * @see https://www.sitepoint.com/interactive-cli-password-prompt-in-php/
 */
function readlineSecure(
    #[SensitiveParameter]
    string $prompt
): string {
    // the following code works fine on W11 somehow
    if (preg_match('/^win/i', PHP_OS)) {
        $vbscript = sys_get_temp_dir() . 'prompt_password.vbs';
        file_put_contents(
            $vbscript,
            'wscript.echo(InputBox("'. addslashes($prompt) .'", "", "password here"))'
        );
        $command = "cscript //nologo " . escapeshellarg($vbscript);
        $password = rtrim(shell_exec($command));
        unlink($vbscript);
        return $password;
    }

    $command = "/usr/bin/env bash -c 'echo OK'";

    if (rtrim(shell_exec($command)) !== 'OK') {
        throw new Exception('Can\'t invoke bash');
    }

    $command = "/usr/bin/env bash -c 'read -s -p \""
        . addslashes($prompt)
        . "\" mypassword && echo \$mypassword'";

    $password = rtrim(shell_exec($command));
    stdOut(message: '', eol: PHP_EOL, prefix: '');

    return $password;
}


/************************************************************************
 * TCP/UDP Requests                                                     *
 ************************************************************************/

/**
 * Makes an authentication request to FRITZ!Box and returns a session ID
 *
 * The authentication process follows these steps:
 * 1. Fetches challenge string from login page
 * 2. Generates password hash using challenge
 * 3. Posts credentials to obtain session ID
 *
 * @param string $endpoint The FRITZ!Box base URL (e.g., http://192.168.1.1)
 * @param string $username The administrator username
 * @param string $password The administrator password
 *
 * @throws Exception When challenge string cannot be found
 * @throws Exception When authentication fails
 * @throws Exception When HTTP request fails
 * @throws Exception With code 400 when login fails
 * @return string Valid session ID for authenticated requests
 */
function makeLoginRequest(
    string $endpoint,
    string $username,
    #[SensitiveParameter]
    string $password
): string {
    // 1. Fetch challenge string from login page
    $fritzLoginGetResponse = makeRequest(
        url: $endpoint,
        method: 'GET'
    );

    if ($fritzLoginGetResponse['status'] !== 200) {
        throw new Exception(
            message: sprintf(
            'Request is failed! HTTP (Code: %s) GET %s',
            (string) $fritzLoginGetResponse['status'],
            $endpoint
            ),
            code: (int) $fritzLoginGetResponse['status']
        );
    }

    if (
        preg_match('#"challenge":"([^"]+)"#i', $fritzLoginGetResponse['body'], $challengeRegexpMatch) === false
        || empty($challengeRegexpMatch[1])
    ) {
        throw new Exception('Unable to find the "challenge" string');
    }

    // 2. Generate password hash using challenge
    $passwordHash = generateLoginPasswordHash(
        challenge: $challengeRegexpMatch[1],
        password: $password
    );

    // 3. Posts credentials to obtain session ID
    $formActionEndpoint = $endpoint .'/index.lua';

    $fritzLoginPostResponse = makeRequest(
        url: $formActionEndpoint,
        method: 'POST',
        headers: [
            ''
        ],
        data: [
            'response' => $passwordHash,
            'lp' => '',
            'loginView' => 'simple',
            'username' => $username
        ]
    );

    if ($fritzLoginPostResponse['status'] !== 200) {
        throw new Exception(
            message: sprintf(
            'Request is failed! HTTP (Code: %s) POST %s',
            (string) $fritzLoginPostResponse['status'],
            $formActionEndpoint
            ),
            code: (int) $fritzLoginPostResponse['status']
        );
    }

    if (
        preg_match('#"sid":"([^"]+)"#i', $fritzLoginPostResponse['body'], $sessionIdRegexpMatch) === false
        || empty($sessionIdRegexpMatch[1])
    ) {
        throw new Exception('Unable to find the "sid" string');
    }

    if (trim($sessionIdRegexpMatch[1], '0') === '') {
        throw new Exception(
            message: 'Login failed',
            code: 400
        );
    }

    return $sessionIdRegexpMatch[1];
}

/**
 * Makes a request to the FRITZ!Box eventlog endpoint and returns available messages
 *
 * Fetches event logs using the FRITZ!Box API v0 and processes the response.
 * Returned logs are sorted by ascending timestamp and include event details
 * like date, time, message, and category.
 *
 * @param string $endpoint The FRITZ!Box base URL (e.g., http://192.168.1.1)
 * @param string $sessionId Valid session ID from successful authentication
 *
 * @throws Exception When HTTP request fails (status != 200)
 * @throws Exception With code 400 when session is invalid/expired
 * @throws Exception When JSON response cannot be decoded
 * @return array<int, array{
 *   timestamp: int,
 *   date: string,
 *   time: string,
 *   id: int,
 *   group: string,
 *   msg: string,
 *   nohelp: bool
 * }> List of event log entries sorted by timestamp
 */
function makeEventLogsRequest(
    string $endpoint,
    string $sessionId
): array {
    $eventLogsEndpoint = $endpoint .'/api/v0/eventlog';

    $response = makeRequest(
        url: $eventLogsEndpoint,
        method: 'GET',
        headers: [
            'AUTHORIZATION: AVM-SID '. $sessionId,
            'Content-Type: application/json',
        ]
    );

    // don't know why they chose 400 code instead of 401
    if ($response['status'] === 400) {
        $eventLogs = json_decode($response['body'], true);
        $responseErrorMessage = 'Unknown';

        // retrieving external error messages if available
        if (is_array($eventLogs) && !empty($eventLogs['errors'])) {
            $responseErrorMessage = implode(', ', array_column($eventLogs['errors'], 'message'));
        }

        throw new Exception(
            message: sprintf(
                'Authentication is invalid or has expired! HTTP Code: %s; Message: %s',
                (string) $response['status'],
                $responseErrorMessage
            ),
            code: (int) $response['status']
        );
    }

    if ($response['status'] !== 200) {
        throw new Exception(
            message: sprintf(
                'Request is failed! HTTP (Code: %s) GET %s',
                (string) $response['status'],
                $eventLogsEndpoint
            ),
            code: (int) $response['status']
        );
    }

    try {
        $eventLogs = json_decode(
            $response['body'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        throw new Exception(
            message: sprintf('Unexpected eventlog output decoding error: %s', $e->getMessage()),
            previous: $e
        );
    }

    $eventLogs = array_map(
        fn($entry) => [
            'timestamp' => DateTime::createFromFormat('d.m.y H:i:s', $entry['date'].' '.$entry['time'])->getTimestamp(),
            'date' => $entry['date'],
            'time' => $entry['time'],
            'id' => $entry['id'],
            'group' => $entry['group'],
            'msg' => $entry['msg'],
            'nohelp' => $entry['nohelp'],
        ],
        $eventLogs
    );

    uasort($eventLogs, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

    return $eventLogs;
}

/**
 * Sends event logs to a syslog server using RFC 3164 format
 *
 * Establishes and maintains a persistent connection to the syslog server.
 * Formats each log entry according to RFC 3164 before sending.
 *
 * @param string $endpoint The syslog server URL (e.g., udp://127.0.0.1:514)
 * @param array<int, array{timestamp: int, ...}> $eventLogs Array of log entries
 * @throws Exception When connection fails or writing to socket fails
 * @return void
 */
function sendLogsToSyslog(
    string $endpoint,
    array $eventLogs
): void {
    static $syslogConnection = null;

    // open the connection once and closes it when script ends
    if ($syslogConnection === null) {
        [$syslogProtocol, $syslogHost, $syslogPort] = substr_count($endpoint, ':') === 2
            ? explode(':', $endpoint, 3)
            : array_merge(explode(':', $endpoint, 2), [514]);

        $syslogConnection = fsockopen(
            hostname: $syslogProtocol .':'. $syslogHost,
            port: (int) $syslogPort,
            error_code: $errno,
            error_message: $errstr,
            timeout: 30
        );
        stdOut('Connection to syslog server established.');

        if ($syslogConnection === false) {
            $syslogConnection = null;

            throw new Exception(sprintf(
                'Syslog server connection error: (%s) %s',
                (string) $errno,
                $errstr
            ));
        }

        $syslogCloseConnectionFunction = function() use($syslogConnection) {
            fclose($syslogConnection);
            stdOut('Connection to syslog server closed.');
            exit(0);
        };

        // if we can handle signalts, attempt to gracefully close the syslog server connection
        if (preg_match('/^win/i', PHP_OS)) {

            sapi_windows_set_ctrl_handler(fn(int $event) => match($event) {
                PHP_WINDOWS_EVENT_CTRL_C => $syslogCloseConnectionFunction(),
                PHP_WINDOWS_EVENT_CTRL_BREAK => $syslogCloseConnectionFunction()
            });

        } elseif (extension_loaded('pcntl')) {

            pcntl_signal(SIGTERM, $syslogCloseConnectionFunction);
            pcntl_signal(SIGINT, $syslogCloseConnectionFunction);

        } else {

            register_shutdown_function($syslogCloseConnectionFunction);

        }
    }

    stdOut('Pushing '. count($eventLogs) .' entries to syslog server.');

    foreach ($eventLogs as $logsEntry) {
        // Facility = 1 (user-level messages)
        // Severity = 5 (notice)
        // Priority = Facility × 8 + Severity = 13
        $priority = 13;
        $timestamp = date("M d H:i:s", $logsEntry['timestamp']);
        $hostname = gethostname();
        $message = sprintf(
            '%s - (id:%s; group:%s) %s',
            SYSLOG_MESSAGE_IDENTIFIER,
            (string) $logsEntry['id'],
            $logsEntry['group'],
            $logsEntry['msg']
        );

        // Build the syslog message (RFC 3164 format)
        $syslogMessage = sprintf(
            '<%s>%s %s %s',
            (string)$priority,
            (string)$timestamp,
            (string)($hostname === false? 'unknown-host' : $hostname),
            "FRITZ!Box - (id:{$logsEntry['id']}; group:{$logsEntry['group']}) {$logsEntry['msg']}"
        );

        // Send the log message to the syslog server
        if (fwrite($syslogConnection, $syslogMessage) === false) {
            $syslogConnection = null;
            throw new Exception("Syslog server error: Unable to push log entries to the remote syslog server");
        }
    }
}

/**
 * Makes an HTTP request using cURL
 *
 * @param string $url The URL to request
 * @param string $method HTTP method (GET, POST, etc.), defaults to 'GET'
 * @param array $headers Optional HTTP headers
 * @param array|null $data Optional POST data
 *
 * @throws Exception When cURL request fails with detailed error message
 * @return array{
 *   status: int,
 *   body: string|bool
 * } Array containing HTTP status code and response body
 */
function makeRequest(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?array $data = null
): array {
    $curlHandler = curl_init();
    curl_setopt($curlHandler, CURLOPT_URL, $url);
    curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curlHandler, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($curlHandler, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curlHandler, CURLOPT_SSL_VERIFYPEER, false);

    // Add custom headers if provided
    if (!empty($headers)) {
        curl_setopt($curlHandler, CURLOPT_HTTPHEADER, $headers);
    }

    // Add POST data if provided
    if ($data !== null) {
        curl_setopt($curlHandler, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $response = curl_exec($curlHandler);

    if ($errno = curl_errno($curlHandler)) {
        throw new Exception(sprintf(
            'cURL Error: (%s) %s',
            (string) $errno,
            curl_error($curlHandler)
        ));
    }

    $statusCode = curl_getinfo($curlHandler, CURLINFO_HTTP_CODE);
    curl_close($curlHandler);

    return [
        'status' => $statusCode,
        'body' => $response
    ];
}

/**
 * Generates a password hash for FRITZ!Box authentication
 *
 * Uses either PBKDF2 or MD5 hashing based on the challenge format:
 * - If challenge contains '$', uses double-pass PBKDF2 with SHA256
 * - Otherwise falls back to legacy MD5 hashing
 *
 * @param string $challenge The challenge string from FRITZ!Box login page
 * @param string $password The administrator password to hash
 *
 * @throws Exception When challenge format is invalid for PBKDF2
 * @throws Exception When challenge version is unsupported
 * @throws Exception When PBKDF2 salts are missing
 * @return string The generated password hash in FRITZ!Box format
 */
function generateLoginPasswordHash(
    string $challenge,
    #[SensitiveParameter]
    string $password
): string {
    // Check if challenge contains '$' for PBKDF2
    if (str_contains($challenge, '$')) {
        $params = parseChallengeString($challenge);

        // First PBKDF2 pass
        $hash1 = hash_pbkdf2(
            'sha256',
            $password,
            $params['salt1'],
            $params['iterations1'],
            32,    // Length in bytes
            true   // Raw binary output
        );

        // Second PBKDF2 pass
        $hash2 = hash_pbkdf2(
            'sha256',
            $hash1,
            $params['salt2'],
            $params['iterations2'],
            32,    // Length in bytes
            true   // Raw binary output
        );

        // Format final response
        return bin2hex($params['salt2']) . '$' . bin2hex($hash2);
    }

    // Fallback to MD5 for non-PBKDF2 challenge
    $resp = $challenge . '-' . $password;
    return $challenge . '-' . md5($resp);
}

/**
 * Parses a FRITZ!Box PBKDF2 challenge string into its components
 *
 * Challenge format is: '2$iterations1$salt1$iterations2$salt2'
 * Example: '2$1000$abc123$2000$def456'
 *
 * @param string $challenge The challenge string to parse
 *
 * @throws Exception When challenge format is invalid (wrong number of parts)
 * @throws Exception When challenge version is not '2'
 * @throws Exception When salts are missing or empty
 * @return array{
 *   salt1: string,
 *   salt2: string,
 *   iterations1: int,
 *   iterations2: int
 * } Parsed challenge components with binary salts and integer iterations
 */
function parseChallengeString(string $challenge): array {
    $parts = explode('$', trim($challenge), 5);

    if (count($parts) < 5) {
        throw new Exception('Invalid challenge format');
    }

    [$version, $iter1, $salt1, $iter2, $salt2] = $parts;

    if ($version !== '2') {
        throw new Exception('Challenge has an unsupported version');
    }

    if (!$salt1 || !$salt2) {
        throw new Exception('Missing salts');
    }

    return [
        'salt1' => hex2bin($salt1),
        'salt2' => hex2bin($salt2),
        'iterations1' => intval($iter1),
        'iterations2' => intval($iter2)
    ];
}

// EOF
