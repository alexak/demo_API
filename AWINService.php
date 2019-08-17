<?php

namespace AppBundle\Service\ProductDB;

use DateTime;
use GuzzleHttp\Client as GuzzleClient;
use AppBundle\Classes\ProductDB\EanCrawlerInterface;
use AppBundle\Classes\ProductDB\ProductAffiliateData;
use GuzzleHttp\Exception\GuzzleException;
use Alchemy\Zippy\Zippy;
use Symfony\Component\Filesystem\Filesystem;


/**
 * Class AWINService
 * imports affiliate products from AWIN network. AWIN will further on replace Affilinet.
 * Doku: http://wiki.awin.com/index.php/Downloading_A_Feed
 * @package AppBundle\Service\ProductDB
 */
class AWINService implements EanCrawlerInterface
{
    private $tmpDir;
    private $fileName;
    protected $apikey;
    protected $programId;
    protected $name;
    private $filesystem;
    private $errorMessage;

    public function __construct($projectDir, $apikey, $name, $programId) {
        $this->tmpDir = $projectDir .'/tmp/productdb/';
        $this->apikey = $apikey;
        $this->name      = $name;
        $this->programId = $programId;

        $this->filesystem = new Filesystem();
        $this->fileName = 'awin-'.$programId;
    }

    /**
     * @param string $productId .. unique Id für das produkt (EAN, ASIN, IKEA Produktid..)
     * @param bool   $http2
     * @param bool   $verbose
     * @param bool   $veryVerbose
     * @param bool   $debug
     * @return ProductAffiliateData|null
     * @throws GuzzleException
     */
    public function getProductAffiliateDataForEan($productId, $http2 = false, $verbose = false, $veryVerbose = false, $debug = false)
    {
        $affilinetProperties = null;
        $csvFileName = $this->tmpDir .$this->fileName .'.csv';

        $fileContent = $this->getFileContent($csvFileName, $debug);

        // parse the extruded csv file
        if($fileContent){
            $productItems = $this->parseCSV($fileContent);

            if (isset($productItems[$productId])){
                $affilinetProperties = $this->initProductAffiliateData($productId, $productItems[$productId]);
            } else {
                $this->errorMessage = " Product with EAN " .$productId ." not found on AWIN network";
            }
        }

        return $affilinetProperties;
    }


    /**
     * function fetches a file with product details from AWIN network.
     * If the csv file does not exist or if the file is older than a day
     * the fonction will fetch a new csv from AWIN network.
     * The function retuŕns an array with the file content
     *
     * @param $fileName string - name of the file.
     * @param $debug  - boolean show va_dumps for debugging purposes..
     * @return array|bool|null
     * @throws GuzzleException
     */
    private function getFileContent($fileName, $debug)
    {
        $getFile = false;
        if (!$this->filesystem->exists($fileName)){
            $getFile = true;
        } else {
            $dateCreated = filemtime ( $fileName );
            $getFile = $this->isOlderThanDay($dateCreated);
        }

        // get AWIN product catalog if the file does not exists or if the file is older than one day.
        if ($getFile) {
            $archiveFile = $this->getCatalogFromRemote($debug);
            if($archiveFile){
                $this->unzip($archiveFile);
            } else {
                // we have a problem :o(
                $this->errorMessage = " Unable to download the product feed.";
                return null;
            }
        }

        return file($fileName);
    }


    /**
     * function that makes an API call to AWIN. It returns a file containing all product informations
     * @param string $productId
     * @param bool   $http2
     * @param bool   $verbose
     * @param bool   $veryVerbose
     * @param bool   $debug
     * @throws GuzzleException
     * @return filename
     */
    private function getCatalogFromRemote($debug = false)
    {
        $productIdType = $this->getProductIdType();

        // available columns in exported file. They are exported in same order than listed here (they can be reordered)
        // Other columns are available for export (but not necessary filled by advertiser)
        // see: http://wiki.awin.com/images/a/a0/PM-FeedColumnDescriptions.pdf
        $columns = [
            'ean',
            'product_GTIN',
            'product_name',
            'description',
            'aw_image_url',
            'store_price',
            'aw_deep_link',
            'aw_product_id',
            'search_price',
            'merchant_name',
            'merchant_id',
            'currency',
            'merchant_deep_link',
            'last_updated',
            'display_price',
            'stock_status'
        ];

        // construct query path
        $endpoint = 'https://productdata.awin.com';
        $path = '/datafeed/download'
            .'/apikey/'.$this->apikey
            .'/language/de/'
            .'fid/' .$this->programId
            .'/columns/'.implode(',', $columns)
            .'/format/csv/'
            .'delimiter/%2C/'
            .'compression/zip/';

        // download the product catalogue
        $guzzleClient = new GuzzleClient([
            'base_uri' => $endpoint,
            'verify' => false,
        ]);
        $response = $guzzleClient->get($path);

        // error handling..
        $isResponseError = ($response->getStatusCode() !== 200);
        if ($debug || $isResponseError){
            var_dump($response->getReasonPhrase());
            if ($isResponseError){
                $this->errorMessage = " AWIN API error" .$response->getReasonPhrase();
                return null;
            }
        }

        if(!$this->filesystem->exists($this->tmpDir)){
            $this->filesystem->mkdir($this->tmpDir);
        }

        $fileName =  $this->tmpDir .$this->fileName .'.zip';
        $this->filesystem->dumpFile($fileName, $response->getBody());

        return $fileName;
    }


    /**
     * function that unzips the file and cleans temporary files up
     */
    private function unzip($archiveFile)
    {
        // unzip file
        $zippy = Zippy::load();
        $archive = $zippy->open($archiveFile);
        $archive->extract($this->tmpDir);

        foreach($archive as $archivedFile){
            $file = $archivedFile->getLocation();
            // we have only one file in zip so this is OK..
            $this->filesystem->rename($this->tmpDir .$file, $this->tmpDir .$this->fileName .'.csv' );
        }

        // delete tmp archive
        $this->filesystem->remove($archiveFile);
    }


    /**
     * function that matches an array composed with cvs fields with their name
     * columns of a cvs file
     * @param $fileContent
     * @return mixed
     */
    protected function parseCSV($fileContent)
    {
        $mappedCsvDatas = [];
        $csvDatas = array_map('str_getcsv', $fileContent);
        $csvDatasLength = count($csvDatas);

        for($i = 1; $i < $csvDatasLength; $i++){
            $mappedCsvColumns = array_combine($csvDatas[0], $csvDatas[$i]);
            $ean = !empty($mappedCsvColumns['ean']) ? $mappedCsvColumns['ean'] : $mappedCsvColumns['product_GTIN'];
            $mappedCsvDatas[$ean] = $mappedCsvColumns;
        }

        return $mappedCsvDatas;
    }


    /**
     * function that extracts the prooduct data from AWIN response and returns an object including these
     * product data
     * @param $productId
     * @return ProductAffiliateData
     */
    protected function initProductAffiliateData($productId, $item)
    {
        // product out of stock = no product..
        if ($item['stock_status'] == 'out of stock'){
            $this->errorMessage = " Out of stock";
            return null;
        }

        $affilinetProperties = new ProductAffiliateData();
        $affilinetProperties->ean = $productId;
        $affilinetProperties->affiliatePartnerIdentifier = $this->name;
        $affilinetProperties->description = $item['description'];
        if(!empty($item['aw_image_url'])){
            $affilinetProperties->imageUrl = $item['aw_image_url'];
        }
        $affilinetProperties->name = $item['product_name'];
        $affilinetProperties->link = $item['merchant_deep_link'];
        if(0 < $item['search_price']){
            $affilinetProperties->bestOffer = $item['search_price'];
        }

        return $affilinetProperties;
    }


    /**
     * function that checks, if a time long is older than a day or not
     */
    protected function isOlderThanDay($date_1 , $date_2 = null)
    {
        $datetime1 = new DateTime();
        $datetime1->setTimestamp($date_1);

        $datetime2 = new DateTime();
        if($date_2) {
            $datetime2->setTimestamp($date_2);
        }

        $interval = $datetime1->diff($datetime2);

        return 0 < intval($interval->format('%a'));
    }


    /**
     *
     * @return string
     */
    protected function getProductIdType()
    {
        return 'EAN';
    }


    public function getErrorMessage()
    {
        return $this->errorMessage;
    }
}

