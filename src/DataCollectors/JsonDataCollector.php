<?php

namespace Apoplavs\Support\AutoDoc\DataCollectors;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Apoplavs\Support\AutoDoc\Interfaces\DataCollectorInterface;
use Apoplavs\Support\AutoDoc\Exceptions\MissedProductionFilePathException;

class JsonDataCollector implements DataCollectorInterface
{
    public $prodFilePath;

    protected static $data;

    /**
     * JsonDataCollector constructor.
     * @throws \Apoplavs\Support\AutoDoc\Exceptions\MissedProductionFilePathException
     */
    public function __construct()
    {
        $this->prodFilePath = config('auto-doc.production_path');

        if (empty($this->prodFilePath)) {
            throw new MissedProductionFilePathException();
        }
    }

    public function saveTmpData($tempData)
    {
        self::$data = $tempData;
    }

    public function getTmpData()
    {
        return self::$data;
    }
    public function saveData()
    {
        $content = json_encode(self::$data);

        file_put_contents($this->prodFilePath, $content);

        self::$data = [];
    }

    /**
     * @return mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getDocumentation()
    {
        if (!file_exists($this->prodFilePath)) {
            throw new FileNotFoundException();
        }

        $fileContent = file_get_contents($this->prodFilePath);

        return json_decode($fileContent);
    }
}
