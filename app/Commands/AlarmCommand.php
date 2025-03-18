<?php

namespace Ballen\Pirrot\Commands;

use Ballen\Clip\Utilities\ArgumentsParser;
use Ballen\Executioner\Exceptions\ExecutionException;
use Ballen\Executioner\Executioner;
use Ballen\GPIO\Adapters\VfsAdapter;
use Ballen\GPIO\Exceptions\GPIOException;
use Ballen\GPIO\GPIO;
use Ballen\Pirrot\Foundation\Config;
use Ballen\Pirrot\Services\AudioService;
use Ballen\Clip\Interfaces\CommandInterface;

define('ALARM_FILE', __DIR__ . '/alarm_status.json');
define('API_URL', 'https://api.alerts.in.ua/v1/alerts/active.json');
define('CHECK_INTERVAL', 60);

/**
 * Class AudioCommand
 *
 * @package Ballen\Pirrot\Commands
 */
class AlarmCommand extends BaseCommand implements CommandInterface
{

    /**
     * The audio service class.
     *
     * @var AudioService
     */
    protected $audioService;

    /**
     * Auto-detected Binary path locations.
     *
     * @var array
     */
    protected $binPaths = [];

    /**
     * AudioCommand constructor.
     *
     * @param ArgumentsParser $argv
     * @throws GPIOException
     */
    public function __construct(ArgumentsParser $argv)
    {

        parent::__construct($argv);

        $alarm = $this->config->get('alerts');

        if(!$alarm){
            return;
        }

        $this->detectExternalBinaries([
            'play'
        ]);

        $this->audioService = new AudioService($this->config);
        $this->audioService->soundPath = $this->basePath . '/resources/sound/';
        $this->audioService->audioPlayerBin = $this->binPaths['play'] . ' -q';

        if (file_exists(ALARM_FILE)) {
            $lastStatus = json_decode(file_get_contents(ALARM_FILE), true);
        } else {
            $lastStatus = ['status' => null];
        }

        $currentStatus = $this->getAlarmStatusFromAPI();

        if ($currentStatus !== $lastStatus['status']) {
            file_put_contents(ALARM_FILE, json_encode(['status' => $currentStatus]));

            $this->outputPtt->setValue(GPIO::HIGH);
            $this->outputLedTx->setValue(GPIO::HIGH);

            if ($currentStatus === 'active') {
                $this->audioService->alarmOn();
            } else {
                $this->audioService->alarmOff();
            }

            $this->outputPtt->setValue(GPIO::LOW);
            $this->outputLedTx->setValue(GPIO::LOW);
        }

        $this->gpio = $this->initGpio();
    }

    private function getAlarmStatusFromAPI()
    {
            $alarm_key = $this->config->get('alerts_key');
            $alerts_location_uid = $this->config->get('alerts_location_uid');

            if(empty($alarm_key) || empty($alerts_location_uid)){
                return false;
            }

            $url = sprintf(API_URL, $alerts_location_uid) . '?token=' . $alarm_key;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $alarm_key
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                return null; // Ошибка запроса
            }

            $data = json_decode($response, true);

            var_dump($data);

            return !empty($data) ? 'active' : 'inactive';
    }

    /**
     * Used to detect external binaries required.
     *
     * @param array $binaries
     */
    private function detectExternalBinaries(array $binaries)
    {
        foreach ($binaries as $bin) {
            $executioner = new Executioner();
            $executioner->setApplication('which')->addArgument($bin);
            try {
                $executioner->execute();
            } catch (ExecutionException $ex) {
                $this->writeln($this->getCurrentLogTimestamp() . 'ERROR: The dependency "' . $bin . '" was not found; please install and/or reference it in your $PATH!');
                $this->exitWithError();
            }
            $this->binPaths[$bin] = trim($executioner->resultAsText());
        }
    }

    /**
     * Used to determine if the machine is GPIO enabled.
     *
     * @return false
     */
    private function detectGpioFilesystem()
    {
        if (file_exists('/sys/class/gpio')) {
            return true;
        }
        return false;
    }

    /**
     * Initialise the GPIO handler object.
     *
     * @return GPIO
     * @throws GPIOException
     */
    private function initGpio()
    {
        //
        return new GPIO(new VfsAdapter());
        //


        $gpio = new GPIO(new VfsAdapter());
        if ($this->detectGpioFilesystem()) {
            $gpio = new GPIO();
        }

        // Configure GPIO pin types.
        $this->inputCos = $gpio->pin(
            $this->config->get('in_cor_pin'),
            GPIO::IN,
            $this->config->get('cos_pin_invert', false)
        );
        $this->outputPtt = $gpio->pin(
            $this->config->get('out_ptt_pin'),
            GPIO::OUT,
            $this->config->get('ptt_pin_invert', false)
        );
        $this->outputLedPwr = $gpio->pin(
            $this->config->get('out_ready_led_pin'),
            GPIO::OUT,
            $this->config->get('ready_pin_invert', false)
        );
        $this->outputLedRx = $gpio->pin(
            $this->config->get('out_rx_led_pin'),
            GPIO::OUT,
            $this->config->get('rx_pin_invert', false)
        );
        $this->outputLedTx = $gpio->pin(
            $this->config->get('out_tx_led_pin'),
            GPIO::OUT,
            $this->config->get('tx_pin_invert', false)
        );

        return $gpio;
    }

    /**
     * Ensures that the Power LED is ON.
     * @throws GPIOException
     */
    protected function setPowerLed(){
        if($this->outputLedPwr->getValue() == GPIO::LOW){
            $this->outputLedPwr->setValue(GPIO::HIGH);
        }
    }

    public function handle()
    {

    }

}