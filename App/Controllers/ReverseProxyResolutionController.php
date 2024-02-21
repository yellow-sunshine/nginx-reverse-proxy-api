<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReverseProxyResolutionController
{

    /**
     * Get and return the details from the reverse proxy configuration for a specified domain.
     *
     * This method retrieves information from the Nginx reverse proxy configuration for the
     * given domain. It returns a JSON representation of the proxy details, including
     * server_name, listening port, and location-specific proxy settings.
     * If the domain is invalid, it responds with a JSON error message and a 400 status code.
     * If the domain is not found in the proxy configuration, it responds with a JSON error
     * message and a 404 status code. In case of success, it responds with a JSON message
     * containing the proxy details and a 200 status code.
     *
     * @param Request $request The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param string $domain The domain for reverse proxy resolution.
     *
     * @return Response The HTTP response containing JSON representation of reverse proxy details
     *                 or an error message with an appropriate status code.
     */
    public function getReverseProxyResoltionJson(Request $request, Response $response, $domain): Response
    {
        if ($this->isValidDomain($domain) == false) {
            $response->getBody()->write(json_encode(['error' => 'Invalid domain']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // Bad Request
        } else {
            $proxyDetails = $this->extractProxyData($domain);
            if ($proxyDetails == false){
                $response->getBody()->write(json_encode(['error' => 'Domain was not found on proxy server']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404); // Not Found
            } else {
                $response->getBody()->write(json_encode(['message' => $proxyDetails]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            }
        }
    }

    /**
     * Extract and return details from the Nginx reverse proxy configuration for a specified domain.
     *
     * This method checks the Nginx sites-enabled directory for the reverse proxy configuration
     * file corresponding to the given domain. It considers subdomains and variations with 'www.'
     * prefix. It returns an array with server details, including server_name, listening port,
     * location-specific proxy settings, modification date, and the raw configuration contents.
     * If the domain or configuration file is not found, it returns false.
     * If an exception occurs during processing, it logs the error, returns false, and handles it.
     *
     * @param string $domain The domain for reverse proxy resolution.
     *
     * @return array|false An array containing details from the reverse proxy configuration, or false
     *                    if the domain is not found or an error occurs during processing.
     */
    private function extractProxyData(string $domain)
    {
        $siteConfigurationDir = '/etc/nginx/sites-enabled';
        // Check if the domain includes a subdomain
        $parsedDomain = parse_url($domain);
        $subdomain = isset($parsedDomain['host']) ? explode('.', $parsedDomain['host'])[0] : '';
        // Check for the configuration file with the given domain
        $siteConfigurationPath = $siteConfigurationDir . '/' . $domain . '.conf';
        // If a subdomain is not present, try with the 'www.' prefix
        if (empty($subdomain) && !file_exists($siteConfigurationPath)) {
            $siteConfigurationPath = $siteConfigurationDir . '/www.' . $domain . '.conf';
        }
        // If a subdomain is present, look for the configuration file with the subdomain
        if (!empty($subdomain)) {
            $subdomainConfigPath = $siteConfigurationDir . '/' . $subdomain . '.' . $domain . '.conf';
            if (file_exists($subdomainConfigPath)) {
                $siteConfigurationPath = $subdomainConfigPath;
            }
        }
        if (!file_exists($siteConfigurationPath)) {
            return false;
        }
        try {
            $modificationDate = $this->getFileModificationDate($siteConfigurationPath);
            $siteConfigurationContents = file_get_contents($siteConfigurationPath);
            $siteDetails = $this->parseNginxConfig($siteConfigurationContents);
            // merge the modification date into the details array
            $siteDetails['modification_date'] = $modificationDate;
            $siteDetails['siteConfigurationContents'] = $siteConfigurationContents;
            return $siteDetails;
        } catch (\Exception $e) {
            // Log and handle the exception
            $logMessage = "Exception in " . __FILE__ . " on line " . $e->getLine() . " in function: " . get_class($e) . ": " . $e->getMessage();
            error_log($logMessage);
            return false;
        }
    }

    /**
     * Parse and extract details from the Nginx reverse proxy configuration.
     *
     * This method takes the raw Nginx configuration as input and extracts
     * relevant details, including server_name, listening port, location-specific
     * proxy settings (such as proxy_pass, proxy_set_headers, proxy_no_cache, proxy_cache_bypass,
     * proxy_connect_timeout, and proxy_read_timeout), and more. It returns an array
     * representing the parsed Nginx configuration. If no server block is found in the
     * configuration, it returns false.
     *
     * @param string $nginxConfig The raw Nginx configuration text.
     *
     * @return array|false An array containing details from the Nginx configuration, or false
     *                    if no server block is found in the configuration.
     */
    private function parseNginxConfig($nginxConfig)
    {
        $parsedConfig = [];
        $currentLocation = null;
        $serverBlockFound = false;
        // Split the nginx config into lines
        $lines = explode("\n", $nginxConfig);
        foreach ($lines as $line) {
            // Remove leading and trailing whitespaces
            $line = trim($line);
            // Ignore empty lines and comments
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            // Check for server block
            if (preg_match('/^\s*server\s*{?\s*$/', $line)) {
                $parsedConfig[] = [
                    'location' => [
                        'proxy_pass' => '', 
                        'proxy_set_headers' => [],
                        'proxy_no_cache' => '',
                        'proxy_cache_bypass' => '',
                        'proxy_connect_timeout' => '',
                        'proxy_read_timeout' => '',
                    ],
                    'server_name' => [],
                ];
                $currentLocation = &$parsedConfig[count($parsedConfig) - 1]['location'];
                $serverBlockFound = true;
                continue;
            }
            if($serverBlockFound){
                if (preg_match('/^listen (\d+);/', $line, $matches)) {
                    $parsedConfig[count($parsedConfig) - 1]['listen'] = (int)$matches[1];
                    continue;
                }
                if (preg_match('/^server_name (.+);/', $line, $matches)) {
                    $parsedConfig[count($parsedConfig) - 1]['server_name'] = explode(' ', $matches[1]);
                    continue;
                }
                if (preg_match('/^\s*location \/ {/', $line)) {
                    continue;
                }
                if (preg_match('/^\s*proxy_pass (.+);/', $line, $matches)) {
                    $currentLocation['proxy_pass'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/^\s*proxy_no_cache (.+);/', $line, $matches)) {
                    $currentLocation['proxy_no_cache'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/^\s*proxy_cache_bypass (.+);/', $line, $matches)) {
                    $currentLocation['proxy_cache_bypass'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/^\s*proxy_connect_timeout (.+);/', $line, $matches)) {
                    $currentLocation['proxy_connect_timeout'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/^\s*proxy_read_timeout (.+);/', $line, $matches)) {
                    $currentLocation['proxy_read_timeout'] = trim($matches[1]);
                    continue;
                }
                // Check for proxy_set_header directives within location block
                if ($currentLocation && preg_match('/^\s*proxy_set_header (\S+) (.+);/', $line, $matches)) {
                    $currentLocation['proxy_set_headers'][$matches[1]] = $matches[2];
                    continue;
                }
            } else {
                continue;
            }
        }
        // Check if at least one server block was found
        if (!$serverBlockFound) {
            return false;
        }
        return $parsedConfig;
    }

    /**
     * Check if the provided domain is valid.
     *
     * This method uses a regular expression to validate the format of the domain name.
     * It checks if the domain consists of lowercase letters, numbers, hyphens, and dots,
     * adhering to the standard domain name format. Returns true if the domain is valid,
     * and false otherwise.
     *
     * @param string $domain The domain to be validated.
     *
     * @return bool True if the domain is valid, false otherwise.
     */
    private function isValidDomain(string $domain): bool {
        # regular expression which validates a domain name
        return (bool)preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,24}$/', $domain);
    }

    /**
     * Get the modification date of the specified file.
     *
     * This method uses the `stat` command to retrieve the modification date
     * of the specified file. It returns the modification date as a string.
     * If the retrieval fails, it logs an error and returns "unknown".
     *
     * @param string $siteConfigurationPath The path to the file.
     *
     * @return string The modification date as a string or "unknown" in case of an error.
     */
    private function getFileModificationDate(string $siteConfigurationPath): string {
        try {
            $command = "stat -c %y \"$siteConfigurationPath\"";
            $output = null;
            $exitStatus = null;
            exec($command, $output, $exitStatus);
            if ($exitStatus === 0 && isset($output[0])) {
                return $output[0];
            } else {
                throw new \Exception("Failed to retrieve file modification date");
            }
        } catch (\Exception $e) {
            $logMessage = "Exception in " . __FILE__ . " on line " . $e->getLine() . " in function: " . get_class($e) . ": " . $e->getMessage();
            error_log($logMessage);
            return "unknown";
        }
    }
}
