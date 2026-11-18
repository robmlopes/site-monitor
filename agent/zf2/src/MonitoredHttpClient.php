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
        $self = $this;
        $spanId = \Monitor::guard(function () use ($self) {
            $spanId = \Monitor::newId(8);
            if (\Monitor::$start !== null && \Monitor::config('propagate')) {
                $headers = $self->getRequest()->getHeaders();
                if ($headers->has('traceparent')) {
                    $headers->removeHeader($headers->get('traceparent')); // cliente reutilizado
                }
                $headers->addHeaderLine('traceparent', \Monitor::traceparent($spanId));
            }
            return $spanId;
        });
        $t = microtime(true);
        try {
            $response = parent::send();
        } catch (\Exception $e) {
            $this->monitorRecord($t, null, $e->getMessage(), $spanId);
            throw $e; // a exceção é do site: segue para ele como sempre
        }
        $status = \Monitor::guard(function () use ($response) {
            return $response ? $response->getStatusCode() : 0;
        }, 0);
        $this->monitorRecord($t, $status, $status >= 500 || $status === 0 ? "HTTP $status" : null, $spanId);
        return $response;
    }

    private function monitorRecord($started, $status, $error, $spanId)
    {
        $self = $this;
        \Monitor::guard(function () use ($self, $started, $status, $error, $spanId) {
            $self->monitorRecordUnsafe($started, $status, $error, $spanId);
        });
    }

    /** Público só para ser chamado de dentro do guard; não use. */
    public function monitorRecordUnsafe($started, $status, $error, $spanId)
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
