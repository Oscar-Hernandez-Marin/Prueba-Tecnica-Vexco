<?php
declare(strict_types=1);

namespace ViesSync;

final class Logger
{
    private $fh;
    private string $path;

    public function __construct(string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se puede crear el directorio de logs: $dir");
        }
        $this->path = $dir . '/vies-' . date('Ymd-His') . '.log';
        $this->fh = fopen($this->path, 'ab');
    }

    public function path(): string { return $this->path; }
    public function info(string $m): void { $this->write('INFO', $m); }
    public function warn(string $m): void { $this->write('WARN', $m); }
    public function error(string $m): void { $this->write('ERROR', $m); }

    public function raw(string $text): void
    {
        fwrite($this->fh, $text . PHP_EOL);
        echo $text . PHP_EOL;
    }

    private function write(string $level, string $m): void
    {
        $this->raw(sprintf('%s [%s] %s', date('c'), $level, $m));
    }
}
