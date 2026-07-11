<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Support\Request;
use App\Support\Response;
use RuntimeException;
use Throwable;

final class AuthController
{
    public function __construct(private AuthService $auth)
    {
    }

    public function health(Request $request): void
    {
        Response::success([
            'service' => 'alnajmo-thagheb-api',
            'time' => date('c'),
        ], 'API آماده است.');
    }

    public function sendOtp(Request $request): void
    {
        $this->handle(function () use ($request) {
            $phone = (string) $request->json('phone', '');
            $data = $this->auth->sendOtp($phone);
            Response::success($data, 'کد تأیید ارسال شد.');
        });
    }

    public function verifyOtp(Request $request): void
    {
        $this->handle(function () use ($request) {
            $phone = (string) $request->json('phone', '');
            $code = (string) $request->json('code', '');
            $data = $this->auth->verifyOtp($phone, $code);
            Response::success($data, 'شماره تأیید شد.');
        });
    }

    public function register(Request $request): void
    {
        $this->handle(function () use ($request) {
            $token = (string) $request->json('registration_token', '');
            $form = [
                'first_name' => $request->json('first_name', $request->json('firstName')),
                'last_name' => $request->json('last_name', $request->json('lastName')),
                'age' => $request->json('age'),
                'school_grade' => $request->json('school_grade', $request->json('schoolGrade')),
                'province' => $request->json('province'),
                'city' => $request->json('city'),
                'quran_reading_level' => $request->json('quran_reading_level', $request->json('quranReadingLevel')),
                'has_previous_class_experience' => (bool) $request->json(
                    'has_previous_class_experience',
                    $request->json('hasPreviousClassExperience', false)
                ),
                'previous_class_details' => $request->json(
                    'previous_class_details',
                    $request->json('previousClassDetails', '')
                ),
            ];
            $data = $this->auth->register($token, $form);
            Response::success($data, 'ثبت‌نام انجام شد.', 201);
        });
    }

    public function skipRegister(Request $request): void
    {
        $this->handle(function () use ($request) {
            $token = (string) $request->json('registration_token', '');
            $data = $this->auth->skipRegistration($token);
            Response::success($data, 'ورود انجام شد.');
        });
    }

    public function me(Request $request): void
    {
        $this->handle(function () use ($request) {
            $data = $this->auth->me($request->bearerToken());
            Response::success($data);
        });
    }

    public function logout(Request $request): void
    {
        $this->handle(function () use ($request) {
            $this->auth->logout($request->bearerToken());
            Response::success([], 'خروج انجام شد.');
        });
    }

    private function handle(callable $fn): void
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (Throwable $e) {
            Response::error('خطای داخلی سرور.', 500, [
                'debug' => $e->getMessage(),
            ]);
        }
    }
}
