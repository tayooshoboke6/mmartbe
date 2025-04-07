<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderExpirationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The order instance.
     *
     * @var \App\Models\Order
     */
    protected $order;

    /**
     * Create a new notification instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = config('app.frontend_url') . '/account/orders/' . $this->order->id;
        
        return (new MailMessage)
            ->subject('Your Order #' . $this->order->order_number . ' Has Expired')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('We noticed that your order #' . $this->order->order_number . ' has been pending payment for some time.')
            ->line('The order has now expired and the items have been returned to our inventory.')
            ->line('If you still wish to purchase these items, please place a new order.')
            ->action('View Order Details', $url)
            ->line('Thank you for shopping with M-MART PLUS ENTERPRISE!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'status' => $this->order->status,
            'expired_at' => $this->order->expired_at,
        ];
    }
}
