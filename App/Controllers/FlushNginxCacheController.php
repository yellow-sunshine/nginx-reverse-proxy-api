<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FlushNginxCacheController
{
    public function flushNginxCache(Request $request, Response $response): Response
    {
        if ($this->executeflushNginxCache() === 0) {
            // Success
            $response->getBody()->write(json_encode(['message' => 'Nginx Cache flushed successfully']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } else {
            // Failed
            $response->getBody()->write(json_encode(['error' => 'Failed to flush Nginx Cache']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    private function executeflushNginxCache()
    {
        //$command = 'sudo rm -r /path/to/cache/*';
        // There is an issue with security here because nginx needs restarted after a cache flush to ensure the cache is rebuilt
        // The issue is allowing a web server to restart a service without any authentication. Addtionally this API is not working with SSL at the time of writing. 
        // THis feater will be sidelined until there is a fix or SSL is implemented/working
        $output = null;
        $exitStatus = null;
        exec($command, $output, $exitStatus);
        return $exitStatus;
    }
}