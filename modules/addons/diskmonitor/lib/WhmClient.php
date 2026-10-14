<?php
namespace DiskMonitor;

class WhmClient {
    private $host;
    private $user;
    private $token;
    private $timeout;

    public function __construct(string $host, string $user, string $token, int $timeout = 15) {
        $this->host = rtrim($host, '/');
        $this->user = $user;
        $this->token = $token;
        $this->timeout = $timeout;
    }

    public function request(string $fn, array $params = []): array {
        $url = $this->host . ':2087/json-api/' . $fn . '?api.version=1&' . http_build_query($params);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('WHM request failed (' . $fn . '): curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: whm ' . $this->user . ':' . $this->token],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException('WHM request failed (' . $fn . '): HTTP ' . $code . ' ' . $err);
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('WHM request failed (' . $fn . '): invalid JSON');
        }
        return $data;
    }

    public function listAccounts(): array {
        $data = $this->request('listaccts');
        if (isset($data['data']['acct']) && is_array($data['data']['acct'])) {
            return $data['data']['acct'];
        }
        return [];
    }

    public function accountSummary(string $user): array {
        $data = $this->request('accountsummary', ['user' => $user]);
        if (isset($data['data']['acct']) && is_array($data['data']['acct'])) {
            return $data['data']['acct'];
        }
        return [];
    }
}
