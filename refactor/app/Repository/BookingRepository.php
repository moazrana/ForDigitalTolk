<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $cuser->jobs()
                    ->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback'])
                    ->whereIn('status', ['pending', 'assigned', 'started'])
                    ->orderBy('due', 'asc')
                    ->get();
                $usertype = 'customer';
            } elseif ($cuser->is('translator')) {
                $jobs = Job::getTranslatorJobs($cuser->id, 'new')->pluck('jobs')->flatten();
                $usertype = 'translator';
            }

            if (isset($jobs)) {
                $emergencyJobs = $jobs->where('immediate', 'yes')->values();
                $normalJobs = $jobs->where('immediate', '!=', 'yes')
                    ->map(function ($job) use ($user_id) {
                        $job['usercheck'] = Job::checkParticularJob($user_id, $job);
                        return $job;
                    })
                    ->sortBy('due')
                    ->values()
                    ->all();
            }
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $cuser,
            'usertype' => $usertype
        ];
    }
    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $pagenum = $request->get('page', 1); // Default to page 1 if 'page' is not provided
        $cuser = User::find($user_id);

        if (!$cuser) {
            return response()->json(['error' => 'User not found'], 404); // Handle case where user is not found
        }

        $usertype = $cuser->is('customer') ? 'customer' : ($cuser->is('translator') ? 'translator' : '');
        $emergencyJobs = [];
        $normalJobs = [];
        $jobs = null;
        $numpages = 0;

        if ($usertype === 'customer') {
            $jobs = $cuser->jobs()
                ->with([
                    'user.userMeta',
                    'user.average',
                    'translatorJobRel.user.average',
                    'language',
                    'feedback',
                    'distance'
                ])
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            return [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => [],
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => 0,
                'pagenum' => $pagenum
            ];
        }

        if ($usertype === 'translator') {
            $jobsPaginator = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
            $totalJobs = $jobsPaginator->total();
            $numpages = ceil($totalJobs / 15);

            $normalJobs = $jobsPaginator;
            $jobs = $jobsPaginator;

            return [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => $normalJobs,
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => $numpages,
                'pagenum' => $pagenum
            ];
        }

        return response()->json(['error' => 'Invalid user type'], 400); // Handle unexpected user type
    }


    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediateTime = 5;

        // Check user type
        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            return $this->errorResponse("Translator cannot create booking");
        }

        $consumerType = $user->userMeta->consumer_type;

        // Validate required fields
        $validationResponse = $this->validateBookingData($data);
        if ($validationResponse) {
            return $validationResponse;
        }

        // Set customer phone and physical type
        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

        // Calculate due date and time
        $response = $this->handleDueDateAndTime($data, $immediateTime);
        if ($response['status'] === 'fail') {
            return $response;
        }

        // Set job attributes
        $data['gender'] = $this->determineGender($data['job_for']);
        $data['certified'] = $this->determineCertification($data['job_for']);
        $data['job_type'] = $this->determineJobType($consumerType);
        $data['b_created_at'] = now();
        $data['will_expire_at'] = isset($data['due']) ? TeHelper::willExpireAt($data['due'], $data['b_created_at']) : null;
        $data['by_admin'] = $data['by_admin'] ?? 'no';

        // Create the job
        $job = $user->jobs()->create($data);

        // Prepare response
        $response['status'] = 'success';
        $response['id'] = $job->id;
        $response['job_for'] = $this->mapJobFor($job);
        $response['customer_physical_type'] = $data['customer_physical_type'];
        $response['customer_town'] = $user->userMeta->city;
        $response['customer_type'] = $user->userMeta->customer_type;

        // Uncomment if needed
        // Event::fire(new JobWasCreated($job, $data, '*'));
        // $this->sendNotificationToSuitableTranslators($job->id, $data, '*');

        return $response;
    }

    private function validateBookingData($data)
    {
        $requiredFields = [
            'from_language_id' => "Du måste fylla in alla fält",
            'due_date' => "Du måste fylla in alla fält",
            'due_time' => "Du måste fylla in alla fält",
            'duration' => "Du måste fylla in alla fält",
        ];

        foreach ($requiredFields as $field => $message) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return $this->errorResponse($message, $field);
            }
        }

        if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
            return $this->errorResponse("Du måste göra ett val här", "customer_phone_type");
        }

        return null;
    }

    private function handleDueDateAndTime(&$data, $immediateTime)
    {
        if ($data['immediate'] === 'yes') {
            $data['due'] = now()->addMinutes($immediateTime)->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            return ['type' => 'immediate'];
        }

        $due = $data['due_date'] . " " . $data['due_time'];
        $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);

        if ($dueCarbon->isPast()) {
            return $this->errorResponse("Can't create booking in past");
        }

        $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
        return ['type' => 'regular'];
    }

    private function determineGender($jobFor)
    {
        if (in_array('male', $jobFor)) return 'male';
        if (in_array('female', $jobFor)) return 'female';
        return null;
    }

    private function determineCertification($jobFor)
    {
        if (in_array('normal', $jobFor) && in_array('certified', $jobFor)) return 'both';
        if (in_array('normal', $jobFor) && in_array('certified_in_law', $jobFor)) return 'n_law';
        if (in_array('normal', $jobFor) && in_array('certified_in_health', $jobFor)) return 'n_health';

        if (in_array('certified', $jobFor)) return 'yes';
        if (in_array('certified_in_law', $jobFor)) return 'law';
        if (in_array('certified_in_health', $jobFor)) return 'health';
        if (in_array('normal', $jobFor)) return 'normal';

        return null;
    }

    private function determineJobType($consumerType)
    {
        return match ($consumerType) {
            'rwsconsumer' => 'rws',
            'ngo' => 'unpaid',
            'paid' => 'paid',
            default => 'unknown',
        };
    }

    private function mapJobFor($job)
    {
        $jobFor = [];
        if ($job->gender) {
            $jobFor[] = $job->gender === 'male' ? 'Man' : 'Kvinna';
        }

        if ($job->certified) {
            if ($job->certified === 'both') {
                $jobFor[] = 'normal';
                $jobFor[] = 'certified';
            } else {
                $jobFor[] = $job->certified;
            }
        }

        return $jobFor;
    }

    private function errorResponse($message, $field = null)
    {
        return [
            'status' => 'fail',
            'message' => $message,
            'field_name' => $field,
        ];
    }


    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $job = Job::findOrFail($data['user_email_job_id'] ?? null);

        // Update job fields
        $this->updateJobDetails($job, $data);

        // Determine email and name
        [$email, $name] = $this->getEmailAndName($job, $data);

        // Send email
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $this->sendJobEmail($email, $name, $subject, $job);

        // Prepare response
        $response = [
            'type' => $data['user_type'] ?? '',
            'job' => $job,
            'status' => 'success',
        ];

        // Trigger event
        $jobData = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $jobData, '*'));

        return $response;
    }

    private function updateJobDetails($job, $data)
    {
        $user = $job->user()->first();

        $job->user_email = $data['user_email'] ?? $job->user_email;
        $job->reference = $data['reference'] ?? '';

        if (isset($data['address'])) {
            $job->address = $data['address'] ?: $user->userMeta->address;
            $job->instructions = $data['instructions'] ?: $user->userMeta->instructions;
            $job->town = $data['town'] ?: $user->userMeta->city;
        }

        $job->save();
    }

    private function getEmailAndName($job, $data)
    {
        if (!empty($job->user_email)) {
            return [$job->user_email, $job->user()->first()->name];
        }

        $user = $job->user()->first();
        return [$user->email, $user->name];
    }

    private function sendJobEmail($email, $name, $subject, $job)
    {
        $sendData = [
            'user' => $job->user()->first(),
            'job' => $job,
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-created', $sendData);
    }


    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        // Initialize job data
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type ?? null,
            'job_for' => $this->getJobForDetails($job),
        ];

        // Split due date and time
        if ($job->due) {
            [$data['due_date'], $data['due_time']] = explode(' ', $job->due);
        }

        return $data;
    }

    private function getJobForDetails($job)
    {
        $jobFor = [];

        // Handle gender-specific jobs
        if ($job->gender === 'male') {
            $jobFor[] = 'Man';
        } elseif ($job->gender === 'female') {
            $jobFor[] = 'Kvinna';
        }

        // Handle certification types
        switch ($job->certified) {
            case 'both':
                $jobFor[] = 'Godkänd tolk';
                $jobFor[] = 'Auktoriserad';
                break;
            case 'yes':
                $jobFor[] = 'Auktoriserad';
                break;
            case 'n_health':
                $jobFor[] = 'Sjukvårdstolk';
                break;
            case 'law':
            case 'n_law':
                $jobFor[] = 'Rättstolk';
                break;
            default:
                if ($job->certified) {
                    $jobFor[] = $job->certified;
                }
                break;
        }

        return $jobFor;
    }


    /**
     * @param array $post_data
     */
    public function jobEnd(array $postData)
    {
        $completedDate = now();
        $jobId = $postData['job_id'];

        $job = Job::with('translatorJobRel')->findOrFail($jobId);
        $job->end_at = $completedDate;
        $job->status = 'completed';
        $job->session_time = $this->calculateSessionTime($job->due, $completedDate);

        $this->notifyUserOfJobCompletion($job);
        $job->save();

        $translatorJob = $job->translatorJobRel
            ->whereNull('completed_at')
            ->whereNull('cancel_at')
            ->first();

        if ($translatorJob) {
            $this->notifyTranslatorOfJobCompletion($job, $translatorJob, $postData['userid']);
            $translatorJob->completed_at = $completedDate;
            $translatorJob->completed_by = $postData['userid'];
            $translatorJob->save();
        }

        Event::fire(new SessionEnded($job, $this->getEventUserId($job, $postData['userid'])));
    }

    private function calculateSessionTime(string $dueDate, Carbon $completedDate): string
    {
        $start = Carbon::parse($dueDate);
        $interval = $start->diff($completedDate);
        return $interval->format('%H:%I:%S');
    }

    private function notifyUserOfJobCompletion(Job $job): void
    {
        $user = $job->user;
        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        $sessionTime = $this->formatSessionTime($job->session_time);
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'faktura'
        ];

        $this->sendEmail(
            $email,
            $name,
            'Information om avslutad tolkning för bokningsnummer # ' . $job->id,
            'emails.session-ended',
            $data
        );
    }

    private function notifyTranslatorOfJobCompletion(Job $job, $translatorJob, $userId): void
    {
        $translator = $translatorJob->user;
        $email = $translator->email;
        $name = $translator->name;

        $sessionTime = $this->formatSessionTime($job->session_time);
        $data = [
            'user' => $translator,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'lön'
        ];

        $this->sendEmail(
            $email,
            $name,
            'Information om avslutad tolkning för bokningsnummer # ' . $job->id,
            'emails.session-ended',
            $data
        );
    }

    private function formatSessionTime(string $sessionTime): string
    {
        [$hours, $minutes] = explode(':', $sessionTime);
        return "{$hours} tim {$minutes} min";
    }

    private function sendEmail(string $email, string $name, string $subject, string $template, array $data): void
    {
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, $template, $data);
    }

    private function getEventUserId(Job $job, int $userId): int
    {
        return ($userId === $job->user_id)
            ? $job->translatorJobRel->first()->user_id
            : $job->user_id;
    }


    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($userId)
    {
        $userMeta = UserMeta::where('user_id', $userId)->firstOrFail();
        $jobType = $this->determineJobType($userMeta->translator_type);

        $userLanguages = UserLanguages::where('user_id', $userId)
            ->pluck('lang_id')
            ->toArray();

        $jobIds = Job::getJobs(
            $userId,
            $jobType,
            'pending',
            $userLanguages,
            $userMeta->gender,
            $userMeta->translator_level
        );

        $filteredJobIds = $this->filterJobsByTown($jobIds, $userId);
        return TeHelper::convertJobIdsInObjs($filteredJobIds);
    }

    private function determineJobType($translatorType)
    {
        return match ($translatorType) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid',
        };
    }

    private function filterJobsByTown($jobIds, $userId)
    {
        return array_filter($jobIds, function ($jobData) use ($userId) {
            $job = Job::find($jobData->id);

            if (!$job) {
                return false;
            }

            $jobUserId = $job->user_id;
            $checkTown = Job::checkTowns($jobUserId, $userId);

            $isPhoneJob = empty($job->customer_phone_type) || $job->customer_phone_type === 'no';
            $isPhysicalJob = $job->customer_physical_type === 'yes';

            return !($isPhoneJob && $isPhysicalJob && !$checkTown);
        });
    }


    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $excludeUserId)
    {
        $users = User::where('user_type', '2')
            ->where('status', '1')
            ->where('id', '!=', $excludeUserId)
            ->get();

        $translatorArray = [];
        $delayedTranslatorArray = [];

        foreach ($users as $user) {
            if (!$this->isPushNotificationEnabled($user, $data['immediate'])) {
                continue;
            }

            $potentialJobs = $this->getPotentialJobIdsWithUserId($user->id);

            foreach ($potentialJobs as $potentialJob) {
                if ($job->id !== $potentialJob->id) {
                    continue;
                }

                $canAcceptJob = $this->canTranslatorAcceptJob($user->id, $potentialJob);
                if ($canAcceptJob) {
                    if ($this->isNeedToDelayPush($user->id)) {
                        $delayedTranslatorArray[] = $user;
                    } else {
                        $translatorArray[] = $user;
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        $msgText = $this->composePushMessage($data);

        $this->logPushNotification($job->id, $translatorArray, $delayedTranslatorArray, $msgText, $data);

        // Send notifications
        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);
        $this->sendPushNotificationToSpecificUsers($delayedTranslatorArray, $job->id, $data, $msgText, true);
    }

    private function isPushNotificationEnabled($user, $isImmediate)
    {
        if (!$this->isNeedToSendPush($user->id)) {
            return false;
        }

        if ($isImmediate === 'yes' && TeHelper::getUsermeta($user->id, 'not_get_emergency') === 'yes') {
            return false;
        }

        return true;
    }

    private function canTranslatorAcceptJob($userId, $job)
    {
        $jobForTranslator = Job::assignedToPaticularTranslator($userId, $job->id);

        if ($jobForTranslator !== 'SpecificJob') {
            return false;
        }

        return Job::checkParticularJob($userId, $job) !== 'userCanNotAcceptJob';
    }

    private function composePushMessage($data)
    {
        $language = $data['language'];
        $duration = $data['duration'];

        if ($data['immediate'] === 'no') {
            return [
                "en" => "Ny bokning för {$language} tolk {$duration}min {$data['due']}"
            ];
        }

        return [
            "en" => "Ny akutbokning för {$language} tolk {$duration}min"
        ];
    }

    private function logPushNotification($jobId, $translatorArray, $delayedTranslatorArray, $msgText, $data)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . now()->toDateString() . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->info("Push sent for job {$jobId}", [$translatorArray, $delayedTranslatorArray, $msgText, $data]);
    }

    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        $message = $this->prepareSMSMessage($job, $jobPosterMeta);

        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info("Sent SMS to {$translator->email} ({$translator->mobile}), status: " . print_r($status, true));
        }

        return count($translators);
    }

    private function prepareSMSMessage($job, $jobPosterMeta)
    {
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?? $jobPosterMeta->city;

        if ($job->customer_physical_type === 'yes' && $job->customer_phone_type === 'no') {
            return trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);
        }

        return trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
    }

    public function isNeedToDelayPush($userId)
    {
        return DateTimeHelper::isNightTime() &&
            TeHelper::getUsermeta($userId, 'not_get_nighttime') === 'yes';
    }

    public function isNeedToSendPush($userId)
    {
        return TeHelper::getUsermeta($userId, 'not_get_notification') !== 'yes';
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );
        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $translatorType = $this->determineTranslatorType($job->job_type);
        $translatorLevel = $this->determineTranslatorLevel($job);

        $blacklist = $this->getBlacklist($job->user_id);
        $translatorsId = $blacklist->pluck('translator_id')->all();

        return User::getPotentialUsers($translatorType, $job->from_language_id, $job->gender, $translatorLevel, $translatorsId);
    }

    private function determineTranslatorType($jobType)
    {
        switch ($jobType) {
            case 'paid':
                return 'professional';
            case 'rws':
                return 'rwstranslator';
            case 'unpaid':
                return 'volunteer';
            default:
                return 'unpaid';
        }
    }

    private function determineTranslatorLevel(Job $job)
    {
        $levels = [];
        if (!empty($job->certified)) {
            $certified = $job->certified;
            if (in_array($certified, ['yes', 'both'])) {
                $levels = array_merge($levels, ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care']);
            } elseif (in_array($certified, ['law', 'n_law'])) {
                $levels[] = 'Certified with specialisation in law';
            } elseif (in_array($certified, ['health', 'n_health'])) {
                $levels[] = 'Certified with specialisation in health care';
            } elseif ($certified === 'normal') {
                $levels[] = 'Layman';
            }
        }
        return $levels ?: ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'];
    }

    private function getBlacklist($userId)
    {
        return UsersBlacklist::where('user_id', $userId)->get();
    }

    public function updateJob($id, $data, $cuser)
    {
        $job = Job::findOrFail($id);
        $logData = [];
        $langChanged = false;

        $currentTranslator = $this->getCurrentTranslator($job);
        $translatorChanged = $this->changeTranslator($currentTranslator, $data, $job);
        if ($translatorChanged['translatorChanged']) {
            $logData[] = $translatorChanged['log_data'];
        }

        $dueChanged = $this->changeDue($job->due, $data['due']);
        if ($dueChanged['dateChanged']) {
            $oldTime = $job->due;
            $job->due = $data['due'];
            $logData[] = $dueChanged['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id']),
            ];
            $oldLang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $statusChanged = $this->changeStatus($job, $data, $translatorChanged['translatorChanged']);
        if ($statusChanged['statusChanged']) {
            $logData[] = $statusChanged['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        $this->logger->addInfo('USER #' . $cuser->id . ' (' . $cuser->name . ') has updated booking #' . $id, $logData);

        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        }

        $this->handleJobUpdates($job, $oldTime, $translatorChanged, $langChanged);
    }

    private function getCurrentTranslator(Job $job)
    {
        return $job->translatorJobRel->where('cancel_at', null)->first() ??
            $job->translatorJobRel->where('completed_at', '!=', null)->first();
    }

    private function handleJobUpdates(Job $job, $oldTime, $translatorChanged, $langChanged)
    {
        if ($translatorChanged['translatorChanged']) {
            $this->sendChangedTranslatorNotification($job, $translatorChanged['old_translator'], $translatorChanged['new_translator']);
        }
        if ($langChanged) {
            $this->sendChangedLangNotification($job, $oldTime);
        }
        if ($translatorChanged['translatorChanged'] || $langChanged) {
            $this->sendChangedDateNotification($job, $oldTime);
        }
    }

    private function changeStatus($job, $data, $changedTranslator)
    {
        $oldStatus = $job->status;
        if ($oldStatus != $data['status']) {
            $statusChanged = $this->handleStatusChange($job, $data, $changedTranslator);
            if ($statusChanged) {
                return ['statusChanged' => true, 'log_data' => ['old_status' => $oldStatus, 'new_status' => $data['status']]];
            }
        }
        return ['statusChanged' => false, 'log_data' => []];
    }

    private function handleStatusChange($job, $data, $changedTranslator)
    {
        switch ($job->status) {
            case 'timedout':
                return $this->changeTimedoutStatus($job, $data, $changedTranslator);
            case 'completed':
                return $this->changeCompletedStatus($job, $data);
            case 'started':
                return $this->changeStartedStatus($job, $data);
            case 'pending':
                return $this->changePendingStatus($job, $data, $changedTranslator);
            case 'withdrawafter24':
                return $this->changeWithdrawafter24Status($job, $data);
            case 'assigned':
                return $this->changeAssignedStatus($job, $data);
            default:
                return false;
        }
    }

    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        if ($data['status'] == 'pending') {
            $job->status = $data['status'];
            $job->created_at = now();
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();

            $this->sendJobReopenedEmail($job);
            $this->sendNotificationTranslator($job, $data, '*');
            return true;
        } elseif ($changedTranslator) {
            $this->sendJobAcceptedEmail($job);
            return true;
        }
        return false;
    }

    private function sendJobReopenedEmail($job)
    {
        $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
        $this->mailer->send($job->user_email, $job->user->name, $subject, 'emails.job-change-status-to-customer', ['job' => $job]);
    }

    private function sendJobAcceptedEmail($job)
    {
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $this->mailer->send($job->user_email, $job->user->name, $subject, 'emails.job-accepted', ['job' => $job]);
    }


    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        if (empty($data['admin_comments'])) {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] === 'completed') {
            $this->handleSessionCompletion($job, $data);
        }

        $job->save();
        return true;
    }

    private function handleSessionCompletion($job, $data)
    {
        if (empty($data['sesion_time'])) {
            return false;
        }

        $interval = $data['sesion_time'];
        $diff = explode(':', $interval);
        $job->end_at = now();
        $job->session_time = $interval;

        $sessionTime = $diff[0] . ' tim ' . $diff[1] . ' min';
        $user = $job->user()->first();
        $this->sendCompletionEmails($job, $user, $sessionTime);

        $translator = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
        $this->sendCompletionEmailsToTranslator($job, $translator, $sessionTime);
    }

    private function sendCompletionEmails($job, $user, $sessionTime)
    {
        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        $dataEmail = [
            'user' => $user,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'faktura',
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    }

    private function sendCompletionEmailsToTranslator($job, $translator, $sessionTime)
    {
        $email = $translator->user->email;
        $name = $translator->user->name;

        $dataEmail = [
            'user' => $translator->user,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'lön',
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    }

    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if (empty($data['admin_comments']) && $data['status'] === 'timedout') {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        $dataEmail = ['user' => $user, 'job' => $job];

        if ($data['status'] === 'assigned' && $changedTranslator) {
            $this->handleAssignedStatus($job, $data, $email, $name, $dataEmail);
        } else {
            $this->handleOtherStatusChanges($job, $email, $name, $dataEmail);
        }

        $job->save();
        return true;
    }

    private function handleAssignedStatus($job, $data, $email, $name, $dataEmail)
    {
        $this->sendJobAcceptedEmail($job, $email, $name, $dataEmail);
        $this->sendTranslatorChangeEmail($job);
        $this->sendSessionStartRemindNotification($job);
    }

    private function sendJobAcceptedEmail($job, $email, $name, $dataEmail)
    {
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);
    }

    private function sendTranslatorChangeEmail($job)
    {
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

        $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
    }

    private function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . now()->toDateString() . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $data = ['notification_type' => 'session_start_remind'];
        $dueExploded = explode(' ', $due);

        $msgText = $this->generateSessionReminderMessage($language, $job, $dueExploded, $duration);

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $usersArray = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($usersArray, $job->id, $data, $msgText, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification', ['job' => $job->id]);
        }
    }

    private function generateSessionReminderMessage($language, $job, $dueExploded, $duration)
    {
        $msgText = [];

        if ($job->customer_physical_type === 'yes') {
            $msgText["en"] = "Detta är en påminnelse om att du har en {$language} tolkning (på plats i {$job->town}) kl {$dueExploded[1]} på {$dueExploded[0]} som vara i {$duration} min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!";
        } else {
            $msgText["en"] = "Detta är en påminnelse om att du har en {$language} tolkning (telefon) kl {$dueExploded[1]} på {$dueExploded[0]} som vara i {$duration} min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!";
        }

        return $msgText;
    }

    private function handleOtherStatusChanges($job, $email, $name, $dataEmail)
    {
        $subject = 'Avbokning av bokningsnr: #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
    }

    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if (empty($data['admin_comments'])) {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }

        return false;
    }

    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if (empty($data['admin_comments']) && $data['status'] === 'timedout') {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];

            $user = $job->user()->first();
            $email = $job->user_email ?: $user->email;
            $name = $user->name;

            $dataEmail = ['user' => $user, 'job' => $job];

            $this->sendJobCancellationEmail($job, $dataEmail, $email, $name);
            $job->save();

            return true;
        }

        return false;
    }

    private function sendJobCancellationEmail($job, $dataEmail, $email, $name)
    {
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

        $translator = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
        if ($translator) {
            $this->mailer->send($translator->user->email, $translator->user->name, $subject, 'emails.job-cancel-translator', $dataEmail);
        }
    }


    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $currentTranslator, $newTranslator)
    {
        $this->sendEmailToCustomer($job, 'emails.job-changed-translator-customer');

        if ($currentTranslator) {
            $this->sendEmailToTranslator($job, $currentTranslator->user, 'emails.job-changed-translator-old-translator');
        }

        $this->sendEmailToTranslator($job, $newTranslator->user, 'emails.job-changed-translator-new-translator');
    }

    public function sendChangedDateNotification($job, $oldTime)
    {
        $this->sendEmailToCustomer($job, 'emails.job-changed-date', ['old_time' => $oldTime]);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->sendEmailToTranslator($job, $translator, 'emails.job-changed-date', ['old_time' => $oldTime]);
    }

    public function sendChangedLangNotification($job, $oldLang)
    {
        $this->sendEmailToCustomer($job, 'emails.job-changed-lang', ['old_lang' => $oldLang]);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->sendEmailToTranslator($job, $translator, 'emails.job-changed-lang', ['old_lang' => $oldLang]);
    }

    public function sendExpiredNotification($job, $user)
    {
        $data = ['notification_type' => 'job_expired'];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msgText = [
            "en" => "Tyvärr har ingen tolk accepterat er bokning: ({$language}, {$job->duration} min, {$job->due}). Vänligen pröva boka om tiden."
        ];

        $this->sendPushNotification($user, $job->id, $data, $msgText);
    }

    public function sendNotificationByAdminCancelJob($jobId)
    {
        $job = Job::findOrFail($jobId);
        $userMeta = $job->user->userMeta()->first();

        $data = $this->prepareJobNotificationData($job, $userMeta);
        $this->sendNotificationTranslator($job, $data, '*'); // Send push notifications to all suitable translators
    }

    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = ['notification_type' => 'session_start_remind'];

        $msgText = [
            "en" => $job->customer_physical_type === 'yes'
                ? "Du har nu fått platstolkningen för {$language} kl {$duration} den {$due}. Vänligen säkerställ att du är förberedd för den tiden. Tack!"
                : "Du har nu fått telefontolkningen för {$language} kl {$duration} den {$due}. Vänligen säkerställ att du är förberedd för den tiden. Tack!"
        ];

        $this->sendPushNotification($user, $job->id, $data, $msgText);
    }

    private function sendEmailToCustomer($job, $template, $additionalData = [])
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        $data = array_merge(['user' => $user, 'job' => $job], $additionalData);
        $subject = "Meddelande om ändring av tolkbokning för uppdrag # {$job->id}";

        $this->mailer->send($email, $name, $subject, $template, $data);
    }

    private function sendEmailToTranslator($job, $translator, $template, $additionalData = [])
    {
        $email = $translator->email;
        $name = $translator->name;

        $data = array_merge(['user' => $translator, 'job' => $job], $additionalData);
        $subject = "Meddelande om ändring av tolkbokning för uppdrag # {$job->id}";

        $this->mailer->send($email, $name, $subject, $template, $data);
    }

    private function sendPushNotification($user, $jobId, $data, $msgText)
    {
        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers([$user], $jobId, $data, $msgText, $this->isNeedToDelayPush($user->id));
        }
    }

    private function prepareJobNotificationData($job, $userMeta)
    {
        $dueDateTime = explode(' ', $job->due);

        return [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $userMeta->city,
            'customer_type' => $userMeta->customer_type,
            'due_date' => $dueDateTime[0],
            'due_time' => $dueDateTime[1],
            'job_for' => $this->getJobFor($job),
        ];
    }

    private function getJobFor($job)
    {
        $jobFor = [];

        if ($job->gender === 'male') {
            $jobFor[] = 'Man';
        } elseif ($job->gender === 'female') {
            $jobFor[] = 'Kvinna';
        }

        if ($job->certified === 'both') {
            $jobFor[] = 'normal';
            $jobFor[] = 'certified';
        } elseif ($job->certified === 'yes') {
            $jobFor[] = 'certified';
        } elseif ($job->certified) {
            $jobFor[] = $job->certified;
        }

        return $jobFor;
    }


    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);

        if (Job::isTranslatorAlreadyBooked($jobId, $user->id, $job->due)) {
            return $this->failResponse('Du har redan en bokning den tiden! Bokningen är inte accepterad.');
        }

        if ($job->status === 'pending' && Job::insertTranslatorJobRel($user->id, $jobId)) {
            $job->status = 'assigned';
            $job->save();

            $this->sendJobAcceptedEmail($job);

            return $this->successResponse(['jobs' => $this->getPotentialJobs($user), 'job' => $job]);
        }

        return $this->failResponse('Jobbet kunde inte accepteras.');
    }

    private function sendJobAcceptedEmail($job)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

        $data = ['user' => $user, 'job' => $job];
        $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
    }

    public function acceptJobWithId($jobId, $user)
    {
        $job = Job::findOrFail($jobId);

        if (Job::isTranslatorAlreadyBooked($jobId, $user->id, $job->due)) {
            return $this->failResponse('Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning');
        }

        if ($job->status === 'pending' && Job::insertTranslatorJobRel($user->id, $jobId)) {
            $job->status = 'assigned';
            $job->save();

            $this->sendJobAcceptedEmail($job);
            $this->sendJobAcceptedPushNotification($job, $user);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            return $this->successResponse([
                'job' => $job,
                'message' => 'Du har nu accepterat och fått bokningen för ' . $language . ' tolk ' . $job->duration . ' min ' . $job->due,
            ]);
        }

        return $this->failResponse('Jobbet kunde inte accepteras.');
    }

    private function sendJobAcceptedPushNotification($job, $user)
    {
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $data = ['notification_type' => 'job_accepted'];
        $msgText = [
            "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . ' min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msgText, $this->isNeedToDelayPush($user->id));
        }
    }

    public function cancelJobAjax($data, $user)
    {
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($user->is('customer')) {
            return $this->handleCustomerCancellation($job, $translator);
        }

        return $this->handleTranslatorCancellation($job, $translator);
    }

    private function handleCustomerCancellation($job, $translator)
    {
        $job->withdraw_at = Carbon::now();
        $hoursBeforeDue = $job->withdraw_at->diffInHours($job->due);

        $job->status = $hoursBeforeDue >= 24 ? 'withdrawbefore24' : 'withdrawafter24';
        $job->save();

        Event::fire(new JobWasCanceled($job));
        $this->sendCancellationNotificationToTranslator($job, $translator);

        return $this->successResponse();
    }

    private function handleTranslatorCancellation($job, $translator)
    {
        if ($job->due->diffInHours(Carbon::now()) <= 24) {
            return $this->failResponse('Du kan inte avboka en bokning som sker inom 24 timmar. Ring +46 73 75 86 865 för att avboka.');
        }

        $customer = $job->user()->first();
        if ($customer) {
            $this->sendCancellationNotificationToCustomer($job, $customer);
        }

        $job->status = 'pending';
        $job->created_at = now();
        $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
        $job->save();

        Job::deleteTranslatorJobRel($translator->id, $job->id);
        $this->sendNotificationTranslator($job, $this->jobToData($job), $translator->id);

        return $this->successResponse();
    }

    private function sendCancellationNotificationToTranslator($job, $translator)
    {
        if ($translator && $this->isNeedToSendPush($translator->id)) {
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $data = ['notification_type' => 'job_cancelled'];
            $msgText = [
                "en" => 'Kunden har avbokat bokningen för ' . $language . ' tolk, ' . $job->duration . ' min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
            ];

            $this->sendPushNotificationToSpecificUsers([$translator], $job->id, $data, $msgText, $this->isNeedToDelayPush($translator->id));
        }
    }

    private function sendCancellationNotificationToCustomer($job, $customer)
    {
        if ($this->isNeedToSendPush($customer->id)) {
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $data = ['notification_type' => 'job_cancelled'];
            $msgText = [
                "en" => 'Er ' . $language . ' tolk, ' . $job->duration . ' min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
            ];

            $this->sendPushNotificationToSpecificUsers([$customer], $job->id, $data, $msgText, $this->isNeedToDelayPush($customer->id));
        }
    }

    public function endJob($data)
    {
        $jobId = $data['job_id'];
        $job = Job::with('translatorJobRel')->findOrFail($jobId);

        if ($job->status !== 'started') {
            return $this->successResponse();
        }

        $completedDate = now();
        $interval = $this->calculateSessionInterval($job->due, $completedDate);

        $job->update([
            'end_at' => $completedDate,
            'status' => 'completed',
            'session_time' => $interval,
        ]);

        $this->sendSessionEndedNotifications($job, $interval, $data['user_id']);

        return $this->successResponse();
    }

    private function calculateSessionInterval($due, $completedDate)
    {
        $start = Carbon::parse($due);
        $end = Carbon::parse($completedDate);
        $diff = $start->diff($end);

        return $diff->format('%H:%I:%S');
    }

    private function sendSessionEndedNotifications($job, $interval, $userId)
    {
        $sessionTime = $this->formatSessionTime($interval);
        $this->sendSessionEndedEmail($job, 'faktura', $sessionTime);

        $translatorRel = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
        Event::fire(new SessionEnded($job, $userId === $job->user_id ? $translatorRel->user_id : $job->user_id));

        $translator = $translatorRel->user()->first();
        $this->sendSessionEndedEmail($job, 'lön', $sessionTime, $translator);

        $translatorRel->update([
            'completed_at' => now(),
            'completed_by' => $userId,
        ]);
    }

    private function sendSessionEndedEmail($job, $forText, $sessionTime, $user = null)
    {
        $user = $user ?: $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => $forText,
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }



    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $currentUser = $request->__authenticatedUser;

        $allJobs = Job::query();

        if ($currentUser->user_type === env('SUPERADMIN_ROLE_ID')) {
            $allJobs = $this->applySuperAdminFilters($allJobs, $requestData);
        } else {
            $allJobs = $this->applyCustomerFilters($allJobs, $requestData, $currentUser->consumer_type);
        }

        $allJobs->with(['user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance']);
        $allJobs->orderBy('created_at', 'desc');

        return $limit === 'all' ? $allJobs->get() : $allJobs->paginate(15);
    }

    private function applySuperAdminFilters($query, $requestData)
    {
        if (!empty($requestData['id'])) {
            $query->whereIn('id', (array) $requestData['id']);
        }

        if (!empty($requestData['lang'])) {
            $query->whereIn('from_language_id', $requestData['lang']);
        }

        if (!empty($requestData['status'])) {
            $query->whereIn('status', $requestData['status']);
        }

        if (!empty($requestData['job_type'])) {
            $query->whereIn('job_type', $requestData['job_type']);
        }

        if (!empty($requestData['customer_email'])) {
            $users = User::whereIn('email', $requestData['customer_email'])->pluck('id');
            $query->whereIn('user_id', $users);
        }

        if (!empty($requestData['translator_email'])) {
            $users = User::whereIn('email', $requestData['translator_email'])->pluck('id');
            $jobIds = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', $users)->pluck('job_id');
            $query->whereIn('id', $jobIds);
        }

        if (!empty($requestData['filter_timetype'])) {
            $query = $this->applyTimeFilter($query, $requestData);
        }

        if (!empty($requestData['consumer_type'])) {
            $query->whereHas('user.userMeta', function ($q) use ($requestData) {
                $q->where('consumer_type', $requestData['consumer_type']);
            });
        }

        return $query;
    }

    private function applyCustomerFilters($query, $requestData, $consumerType)
    {
        $query->where('job_type', $consumerType === 'RWS' ? 'rws' : 'unpaid');

        if (!empty($requestData['id'])) {
            $query->where('id', $requestData['id']);
        }

        if (!empty($requestData['lang'])) {
            $query->whereIn('from_language_id', $requestData['lang']);
        }

        if (!empty($requestData['status'])) {
            $query->whereIn('status', $requestData['status']);
        }

        if (!empty($requestData['job_type'])) {
            $query->whereIn('job_type', $requestData['job_type']);
        }

        if (!empty($requestData['customer_email'])) {
            $user = User::where('email', $requestData['customer_email'])->first();
            if ($user) {
                $query->where('user_id', $user->id);
            }
        }

        if (!empty($requestData['filter_timetype'])) {
            $query = $this->applyTimeFilter($query, $requestData);
        }

        return $query;
    }

    private function applyTimeFilter($query, $requestData)
    {
        $timeField = $requestData['filter_timetype'] === 'created' ? 'created_at' : 'due';

        if (!empty($requestData['from'])) {
            $query->where($timeField, '>=', $requestData['from']);
        }

        if (!empty($requestData['to'])) {
            $to = $requestData['to'] . " 23:59:00";
            $query->where($timeField, '<=', $to);
        }

        $query->orderBy($timeField, 'desc');

        return $query;
    }
    public function alerts()
    {
        $jobs = Job::all();
        $alertJobs = $jobs->filter(function ($job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) < 3) {
                return false;
            }

            $diffMinutes = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
            return $diffMinutes >= $job->duration * 2;
        });

        $jobIds = $alertJobs->pluck('id');
        $languages = Language::active()->orderBy('language')->get();
        $customers = User::where('user_type', '1')->pluck('email');
        $translators = User::where('user_type', '2')->pluck('email');

        $allJobs = Job::with('language')
            ->whereIn('id', $jobIds)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $customers,
            'all_translators' => $translators,
        ];
    }

    private function setIgnoreFlag($model, $id, $attribute = 'ignore')
    {
        $item = $model::findOrFail($id);
        $item->{$attribute} = 1;
        $item->save();

        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        return $this->setIgnoreFlag(Job::class, $id, 'ignore_expired');
    }

    public function ignoreThrottle($id)
    {
        return $this->setIgnoreFlag(Throttles::class, $id);
    }
    public function reopen(Request $request)
    {
        $jobId = $request->input('jobid');
        $userId = $request->input('userid');
        $job = Job::findOrFail($jobId);

        if ($job->status !== 'timedout') {
            $this->updateJobAsPending($job);
        } else {
            $job = $this->reopenTimedOutJob($job, $jobId);
        }

        $this->updateTranslatorJobRel($jobId);
        $this->createTranslatorRelation($jobId, $userId);

        $this->sendNotificationByAdminCancelJob($job->id);

        return ['success' => true, 'message' => 'Job reopened successfully'];
    }

    private function updateJobAsPending($job)
    {
        $job->update([
            'status' => 'pending',
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job->due, now()),
        ]);
    }

    private function reopenTimedOutJob($job, $originalJobId)
    {
        $newJobData = $job->toArray();
        unset($newJobData['id']);

        $newJobData['status'] = 'pending';
        $newJobData['created_at'] = now();
        $newJobData['updated_at'] = now();
        $newJobData['will_expire_at'] = TeHelper::willExpireAt($job->due, now());
        $newJobData['cust_16_hour_email'] = 0;
        $newJobData['cust_48_hour_email'] = 0;
        $newJobData['admin_comments'] = "This booking is a reopening of booking #{$originalJobId}";

        return Job::create($newJobData);
    }

    private function updateTranslatorJobRel($jobId)
    {
        Translator::where('job_id', $jobId)
            ->whereNull('cancel_at')
            ->update(['cancel_at' => now()]);
    }

    private function createTranslatorRelation($jobId, $userId)
    {
        Translator::create([
            'job_id' => $jobId,
            'user_id' => $userId,
            'created_at' => now(),
            'cancel_at' => now(),
        ]);
    }
    /**
     * Converts minutes to hours and minutes format.
     *
     * @param  int    $time   Time in minutes
     * @param  string $format Output format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return "{$time}min";
        }

        if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = $time % 60;

        return sprintf($format, $hours, $minutes);
    }
}
