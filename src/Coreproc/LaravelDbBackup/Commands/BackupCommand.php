<?php namespace Coreproc\LaravelDbBackup\Commands;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use AWS;
use Config;
use Guzzle\Http;

class BackupCommand extends BaseCommand
{
    protected $name = 'db:backup';
    protected $description = 'Backup the default database to `app/storage/dumps`';
    protected $filePath;
    protected $fileName;

    public function fire()
    {
        $database = $this->getDatabase(Config::get('database.default', false));

        if (!empty($this->option('database'))) {
            $database = $this->getDatabase($this->input->getOption('database'));
        }

        $this->checkDumpFolder();

        if ($this->argument('filename')) {
            // Is it an absolute path?
            if (substr($this->argument('filename'), 0, 1) == '/') {
                $this->filePath = $this->argument('filename');
                $this->fileName = basename($this->filePath);
            } // It's relative path?
            else {
                $this->filePath = getcwd() . '/' . $this->argument('filename');
                $this->fileName = basename($this->filePath) . '_' . time();
            }
        } else {
            $this->fileName = $this->input->getOption('database') . '_' . time() . '.' . $database->getFileExtension();
            $this->filePath = rtrim($this->getDumpsPath(), '/') . '/' . $this->fileName;
        }

        $status = $database->dump($this->filePath);

        if ($status === true) {
            if ($this->argument('filename')) {
                $this->line(sprintf($this->colors->getColoredString("\n" . 'Database backup was successful. Saved to %s' . "\n", 'green'), $this->filePath));
            } else {
                $this->line(sprintf($this->colors->getColoredString("\n" . 'Database backup was successful. %s was saved in the dumps folder.' . "\n", 'green'), $this->fileName));
            }

            if ($this->option('upload-s3')) {
                $this->uploadS3();
                $this->line($this->colors->getColoredString("\n" . 'Upload complete.' . "\n", 'green'));
                if ($this->option('data-retention-s3')) {
                    $this->dataRetentionS3();
                }
            }

            $databaseConnectionConfig = Config::get('database.connections.' . $this->input->getOption('database'));
            if (!empty($databaseConnectionConfig['slackToken']) && !empty($databaseConnectionConfig['slackSubDomain'])) {
                $disableSlack = !empty($this->option('disable-slack'));
                if (!$this->option('disable-slack')) $this->notifySlack($databaseConnectionConfig);
            }


        } else {
            // todo
            $this->line(sprintf($this->colors->getColoredString("\n" . 'Database backup failed. %s' . "\n", 'red'), $status));
        }
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array(
            array('filename', InputArgument::OPTIONAL, 'Filename or -path for the dump.'),
        );
    }

    protected function getOptions()
    {
        return array(
            array('database', null, InputOption::VALUE_REQUIRED, 'The database connection to backup'),
            array('upload-s3', 'u', InputOption::VALUE_OPTIONAL, 'Upload the dump to your S3 bucket'),
            array('path-s3', null, InputOption::VALUE_OPTIONAL, 'The folder in which to save the backup'),
            array('data-retention-s3', null, InputOption::VALUE_OPTIONAL, 'Number of days to retain backups'),
            array('disable-slack', null, InputOption::VALUE_NONE, 'Number of days to retain backups'),
        );
    }

    protected function checkDumpFolder()
    {
        $dumpsPath = $this->getDumpsPath();

        if (!is_dir($dumpsPath)) {
            mkdir($dumpsPath);
        }
    }

    protected function uploadS3()
    {
        $bucket = $this->option('upload-s3');
        $s3     = AWS::get('s3');

        $s3->putObject(array(
            'Bucket'     => $bucket,
            'Key'        => $this->getS3DumpsPath() . '/' . $this->fileName,
            'SourceFile' => $this->filePath,
        ));
    }

    protected function getS3DumpsPath()
    {
        if ($this->input->getOption('path-s3')) {
            $path = $this->input->getOption('path-s3');
        } else {
            $path = Config::get('laravel-db-backup::s3.path', 'databases');
        }

        return $path;
    }

    private function dataRetentionS3()
    {
        if (!$this->option('data-retention-s3')) {
            return;
        }

        $dataRetention = (int)$this->input->getOption('data-retention-s3');

        if ($dataRetention <= 0) {
            $this->error("Data retention should be a number");
            return;
        }

        $bucket = $this->option('upload-s3');
        $s3     = AWS::get('s3');

        $list = $s3->listObjects(array(
            'Bucket' => $bucket,
            'Marker' => $this->getS3DumpsPath(),
        ));

        $timestampForRetention = strtotime('-' . $dataRetention . ' days');
        $this->info('Retaining data where date is greater than ' . date('Y-m-d', $timestampForRetention));

        $contents = $list['Contents'];

        $deleteCount = 0;
        foreach ($contents as $fileArray) {
            $filePathArray = explode('/', $fileArray['Key']);
            $filename      = $filePathArray[count($filePathArray) - 1];

            $filenameExplode = explode('_', $filename);

            $fileTimestamp = explode('.', $filenameExplode[count($filenameExplode) - 1])[0];

            if ($timestampForRetention > $fileTimestamp) {
                $this->info("The following file is beyond data retention and was deleted: {$fileArray['Key']}");
                // delete
                $s3->deleteObject(array(
                    'Bucket' => $bucket,
                    'Key'    => $fileArray['Key']
                ));
                $deleteCount++;
            }
        }

        if ($deleteCount > 0) {
            $this->info($deleteCount . ' file(s) were deleted.');
        }

        $this->info("");
    }

    private function notifySlack($databaseConfig)
    {
        $this->info('Sending slack notification..');
        $data['text']     = "A backup of the {$databaseConfig['database']} at {$databaseConfig['host']} has been created.";
        $data['username'] = "Database Backup";
        $data['icon_url'] = "https://s3-ap-northeast-1.amazonaws.com/coreproc/images/icon_database.png";

        $content = json_encode($data);

        $command = "curl -X POST --data-urlencode 'payload={$content}' 'https://{$databaseConfig['slackSubDomain']}.slack.com/services/hooks/incoming-webhook?token={$databaseConfig['slackToken']}'";

        shell_exec($command);
        $this->info('Slack notification sent!');
    }

}
