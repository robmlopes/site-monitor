<?php
// Carregado só pelo HttpClientInterceptor, depois da cópia MonitorOriginalClient.
namespace Zend\Http;

class Client extends MonitorOriginalClient
{
    public function send(Request $request = null)
    {
        if ($request) {
            $this->setRequest($request);
        }
        $spanId = \Monitor::newId(8);
        if (\Monitor::$start !== null && \Monitor::config('propagate')) {
            $headers = $this->getRequest()->getHeaders();
            if ($headers->has('traceparent')) {
                $headers->removeHeader($headers->get('traceparent')); // cliente reutilizado
            }
            $headers->addHeaderLine('traceparent', \Monitor::traceparent($spanId));
        }
        $t = microtime(true);
        try {
            $response = parent::send();
        } catch (\Exception $e) {
            $this->monitorRecord($t, null, $e->getMessage(), $spanId);
            throw $e;
        }
        $status = $response ? $response->getStatusCode() : 0;
        $this->monitorRecord($t, $status, $status >= 500 || $status === 0 ? "HTTP $status" : null, $spanId);
        return $response;
    }

    private function monitorRecord($started, $status, $error, $spanId)
    {
        $request = $this->getRequest();
        $url = (string) $request->getUriString();
        $method = $request->getMethod();
        \Monitor::record('http', \Monitor::hostOf($url), $method . ' ' . \Monitor::safeUrl($url), $started,
            $error === null, $error, array(
                'method' => $method,
                'url'    => \Monitor::safeUrl($url),
                'status' => (int) $status,
                'total'  => round((microtime(true) - $started) * 1000, 2),
                'client' => 'Zend\Http\Client/' . substr(strrchr(get_class($this->getAdapter()), '\\'), 1),
            ), $spanId);
    }
}
