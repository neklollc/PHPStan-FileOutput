<?php
declare(strict_types=1);

namespace noximo;

use Nette\IOException;
use Nette\Utils\FileSystem;
use Nette\Utils\RegexpException;
use Nette\Utils\Strings;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use Symfony\Component\Console\Style\OutputStyle;

class FileOutput implements ErrorFormatter
{
    public const ERROR = 'error';

    public const LINK = 'link';

    public const LINE = 'line';

    public const FILES = 'files';

    public const UNKNOWN = 'unknown';

    /** @var string */
    private $link = 'editor://open/?file=%file&line=%line';

    /**
     * @var ErrorFormatter|null
     */
    private $defaultFormatter;

    /**
     * @var string
     */
    private $outputFile;

    /**
     * @var string
     */
    private $template;

    /**
     * FileOutput constructor.
     */
    public function __construct(string $outputFile, ?ErrorFormatter $defaultFormatterClass = null, ?string $customTemplate = null)
    {
        $this->defaultFormatter = $defaultFormatterClass;

        try {
            $outputFile = Strings::replace($outputFile, '{time}', (string) time());
        } catch (RegexpException $e) {
        }

        $outputFile = realpath($outputFile);
        if ($outputFile !== false) {
            $this->outputFile = $outputFile;
        }

        $customTemplateFile = $customTemplate !== null ? realpath($customTemplate) : false;
        if ($customTemplateFile !== false) {
            $this->template = $customTemplateFile;
        } else {
            $this->template = __DIR__ . '/table.phtml';
        }
    }

    /**
     * Formats the errors and outputs them to the console.
     * @return int Error code.
     */
    public function formatErrors(AnalysisResult $analysisResult, OutputStyle $style): int
    {
        try {
            if ($this->outputFile === null) {
                throw new IOException('Real path of file could not be resolved');
            }
            $this->generateFile($analysisResult);
            $style->writeln('Note: Analysis outputted into file ' . $this->outputFile . '.');
        } catch (IOException $e) {
            $style->error('Analysis could not be outputted into file. ' . $e->getMessage());
        }
        if ($this->defaultFormatter !== null) {
            $this->defaultFormatter->formatErrors($analysisResult, $style);
        }

        return $analysisResult->hasErrors() ? 1 : 0;
    }

    /**
     * @param mixed[] $data
     */
    public function getTable(array $data): string
    {
        ob_start(function (): void {
        });
        require $this->template;

        $output = ob_get_clean();

        return $output !== false ? $output : 'Output failed.';
    }

    private function generateFile(AnalysisResult $analysisResult): void
    {
        $output = [
            self::UNKNOWN => [],
            self::FILES => [],
        ];
        if ($analysisResult->hasErrors()) {
            foreach ($analysisResult->getNotFileSpecificErrors() as $notFileSpecificError) {
                $output[self::UNKNOWN][] = $notFileSpecificError;
            }

            foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
                $file = $fileSpecificError->getFile();
                $line = $fileSpecificError->getLine() ?? 1;
                $link = strtr($this->link, ['%file' => $file, '%line' => $line]);
                $output[self::FILES][$file][] = [
                    self::ERROR => $this->formatMessage($fileSpecificError->getMessage()),
                    self::LINK => $link,
                    self::LINE => $line,
                ];
            }

            foreach ($output[self::FILES] as &$file) {
                usort($file, function ($a, $b) {
                    return -1 * ($a[self::LINE] <=> $b[self::LINE]);
                });
            }

            FileSystem::write($this->outputFile, $this->getTable($output));
        }
    }

    private function formatMessage(string $message): string
    {
        $words = explode(' ', $message);
        $words = array_map(function ($word) {
            if (Strings::match($word, '/[^a-zA-Z,.]|(string)|(bool)|(boolean)|(int)|(integer)|(float)/')) {
                $word = '<b>' . $word . '</b>';
            }

            return $word;
        }, $words);

        return implode(' ', $words);
    }
}
