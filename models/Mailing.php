<?php /** @noinspection PhpUndefinedClassInspection */


namespace app\models;


use app\models\database\Bill;
use app\models\database\Cottage;
use app\models\database\Mail;
use app\models\database\MailingSchedule;
use Exception;
use Throwable;
use Yii;
use yii\db\StaleObjectException;
use yii\web\NotFoundHttpException;

class Mailing
{

    /**
     * @return array
     * @throws NotFoundHttpException
     */
    public static function createMailing(): array
    {
        $title = Yii::$app->request->post('title');
        $body = Yii::$app->request->post('body');
        $mails = Yii::$app->request->post('addresses');
        $mailsList = '<mails>';
        if (empty($title)) {
            return ['message' => 'Не заполнен заголовок рассылки!'];
        }
        if (empty($body)) {
            return ['message' => 'Не заполнен текст рассылки!'];
        }
        if (empty($mails)) {
            return ['message' => 'Не выбраны адреса для рассылки!'];
        }
        $parsedMails = explode(',', $mails);
        $mailing = new database\Mailing();
        $mailing->title = $title;
        $mailing->body = $body;
        $mailing->save();
        foreach ($parsedMails as $mail) {
            $existentMail = Mail::getMailById($mail);
            if ($existentMail === null) {
                return ['message' => 'Не найден адрес почты, возможно, удалён!'];
            }
            $mailsList .= "<mail id='{$existentMail->id}'/>";
            $mailingSchedule = new MailingSchedule();
            $mailingSchedule->mailId = $existentMail->id;
            $mailingSchedule->mailingId = $mailing->id;
            $mailingSchedule->save();
        }
        $mailsList .= '</mails>';
        $mailing->mails_info = $mailsList;
        $mailing->save();
        return ['status' => 1];
    }

    public static function sendBillNotifications(): array
    {
        $billId = trim(Yii::$app->request->post('billNumber'));
        // найду информацию о платеже
        $bill = Bill::findOne($billId);
        if (empty($bill)) {
            return ['message' => 'Счёт не найден'];
        }
        $cottage = Cottage::findOne($bill->cottage);

        if (empty($cottage)) {
            return ['message' => 'Участок не найден'];
        }
        $mails = Mail::getCottageMails($cottage);
        if (empty($mails)) {
            return ['message' => 'У данного участка отсутствуют зарегистрированные адреса электронной почты'];
        }
        foreach ($mails as $mail) {
            $newMailSchedule = new MailingSchedule();
            $newMailSchedule->mailId = $mail->id;
            $newMailSchedule->billId = $bill->id;
            $newMailSchedule->save();
        }
        return ['status' => 1];
    }

    public static function cancelMailing(): ?array
    {
        $id = trim(Yii::$app->request->post('id'));
        if (!empty($id)) {
            $waitingMail = MailingSchedule::find()->where(['id' => $id])->one();
            if (empty($waitingMail)) {
                return ['message' => 'Похоже, данное письмо уже удалено из очереди, попробуйте обновить данную страницу.'];
            }
            try {
                $waitingMail->delete();
            } catch (StaleObjectException $e) {
            } catch (Throwable $e) {
                die('Не удалось удалить сообщение');
            }
            return ['status' => 1];
        }

        return ['message' => 'Не найден идентификатор сообщения.'];
    }

    /**
     * @return array
     * @throws NotFoundHttpException
     * @throws StaleObjectException
     * @throws Throwable
     */
    public static function sendMessage(): array
    {
        $id = trim(Yii::$app->request->post('id'));
        if (!empty($id)) {
            $waitingMail = MailingSchedule::find()->where(['id' => $id])->one();
            if (empty($waitingMail)) {
                return ['message' => 'Похоже, данное письмо уже удалено из очереди, попробуйте обновить данную страницу.'];
            }
            $mailInfo = Mail::getMailById($waitingMail->mailId);
            if ($mailInfo === null) {
                return ['message' => 'Не найдены сведения об адресе электронной почты.'];
            }
            $mailSettings = new MailSettings();
            $mailAddress = $mailSettings->is_test ? $mailSettings->test_mail : $mailInfo->email;
            // создам тело и заголовок письма
            if (!empty($waitingMail->billId)) {
                $bill = Bill::findOne($waitingMail->billId);
                if ($bill === null) {
                    return ['message' => 'Не найден счёт'];
                }
                $fileInfo = PDFHandler::saveBillPdf($bill->id);
                $theme = 'Вам выставлен счёт за услуги СНТ';
                $body = Yii::$app->controller->renderPartial('/mail/root-template', ['bill' => $bill, 'mail' => $mailInfo]);
                $sending = self::send($mailAddress,
                    GrammarHandler::handlePersonals($mailInfo->fio),
                    $theme,
                    $body,
                    [$fileInfo]);
                $bill->is_sended = 1;
                $bill->setScenario(Bill::SCENARIO_CREATE);
                $bill->save();
            } else if (!empty($waitingMail->mailingId)) {
                $mailing = database\Mailing::findOne($waitingMail->mailingId);
                if ($mailing === null) {
                    return ['message' => 'Рассылка не найдена'];
                }
                $theme = urldecode($mailing->title);
                $body = Yii::$app->controller->renderPartial('/mail/root-template', ['mailing' => $mailing, 'mail' => $mailInfo]);
                $sending = self::send($mailAddress,
                    GrammarHandler::handlePersonals($mailInfo->fio),
                    $theme,
                    $body);
            } elseif (!empty($waitingMail->bills)) {
                // получу данные о счетах, сгенерирую PDF для каждого, отправлю письмо с несколькими вложениями и удалю счета
                $bills = explode(',', $waitingMail->bills);
                $attachments = [];
                foreach ($bills as $bill) {
                    $bill = Bill::findOne($bill);
                    // проверю, что номер участка счёта совпадает с номером участка почтового адреса
                    if ($bill !== null && $bill->cottage === $mailInfo->cottage_num) {
                        $fileInfo = PDFHandler::saveBillPdf($bill->id, true);
                        $attachments[] = $fileInfo;
                    } else {
                        return ['message' => "Не совпадает номер участка в счёте ({$bill->cottageNumber}) и почте ({$mailInfo->cottage_num})"];
                    }
                }
                if (!empty($attachments)) {
                    try {
                        $theme = 'Вам выставлены счёта за услуги СНТ';
                        $body = Yii::$app->controller->renderPartial('/mail/root-template', ['mail' => $mailInfo, 'bills' => $bills]);
                        // тут отправлю письмо
                        $sending = self::send($mailAddress,
                            GrammarHandler::handlePersonals($mailInfo->fio),
                            $theme,
                            $body,
                            $attachments);
                        // тут удалю файлы
                    } finally {
                        foreach ($attachments as $attachment) {
                            unlink($attachment['url']);
                        }
                    }
                }
            } else {
                return ['message' => 'Не найден контент письма'];
            }

            if ($sending['status'] === 'sended') {
                $waitingMail->delete();
                if (!empty($waitingMail->billId)) {
                    $bill = Bill::findOne($waitingMail->billId);
                    $bill->setScenario(Bill::SCENARIO_EDIT);
                    $bill->is_sended = 1;
                    $bill->save();
                }
                return ['status' => 1];
            }

            return ['message' => 'Отправка не удалась, текст ошибки- "' . $sending['error'] . '"'];
        }

        return ['message' => 'Не найден идентификатор сообщения.'];
    }


    /**
     * @param $address
     * @param $receiverName
     * @param $subject
     * @param $body
     * @param null $attachment
     * @return array
     */
    public static function send($address, $receiverName, $subject, $body, $attachment = null): ?array
    {
        $form = new MailSettings();
        $mail = Yii::$app->mailer->compose()
            ->setFrom([$form->address => $form->snt_name])
            ->setSubject($subject)
            ->setHtmlBody($body)
            ->setTo([$address => $receiverName]);

        if (!empty($attachment)) {
            foreach ($attachment as $item) {
                $mail->attach($item['url'], ['fileName' => $item['name']]);
            }
        }
        try {
            $mail->send();
            return ['status' => 'sended'];
        } catch (Exception $e) {
            // отправка не удалась
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    public static function saveMailTemplate(): array
    {
        $text = Yii::$app->request->post('template');
        if (!empty($text)) {
            $encodedText = urldecode($text);
            // сохраню шаблон в файл
            $filename = dirname($_SERVER['DOCUMENT_ROOT'] . './/') . '/settings/mail_template';
            file_put_contents($filename, $encodedText);
            return ['status' => 1];
        }

        return ['message' => 'Шаблон пуст'];
    }

    public static function getMailingTemplate()
    {
        $filename = dirname($_SERVER['DOCUMENT_ROOT'] . './/') . '/settings/mail_template';
        if (is_file($filename)) {
            $content = file_get_contents($filename);
        }
        if (empty($content)) {
            return 'Заполните шаблон письма в разделе настроек';
        }
        return $content;
    }
}