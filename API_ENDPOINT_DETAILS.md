# M-Mart+ API Endpoint Documentation

This document provides a comprehensive list of all API endpoints available in the M-Mart+ backend system. The endpoints are organized by functionality.

## Table of Contents
- [Authentication](#authentication)
- [Products & Categories](#products--categories)
- [Product Sections](#product-sections)
- [Cart Management](#cart-management)
- [User Profile](#user-profile)
- [User Notifications](#user-notifications)
- [User Addresses](#user-addresses)
- [Orders & Checkout](#orders--checkout)
- [Payments](#payments)
- [Coupons](#coupons)
- [Delivery](#delivery)
- [Store Locations](#store-locations)
- [Notification Bar](#notification-bar)
- [Admin Routes](#admin-routes)
  - [Dashboard](#dashboard)
  - [Settings Management](#settings-management)
  - [Delivery Settings](#delivery-settings)
  - [Banner Management](#banner-management)
  - [Notification Bar Management](#notification-bar-management)
  - [Product Management](#product-management)
  - [Category Management](#category-management)
  - [Order Management](#order-management)
  - [User Management](#user-management)
  - [Coupon Management](#coupon-management)
  - [Message Campaign Management](#message-campaign-management)

## Authentication

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| POST | `/api/register` | Register a new user | No |
| POST | `/api/login` | Login user and get token | No |
| POST | `/api/auth/google` | Google social authentication | No |
| POST | `/api/auth/apple` | Apple social authentication | No |
| POST | `/api/forgot-password` | Send password reset link | No |
| POST | `/api/reset-password` | Reset password with token | No |
| POST | `/api/logout` | Logout user (revoke token) | Yes |
| POST | `/api/refresh-token` | Refresh authentication token | Yes |

## Products & Categories

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| GET | `/api/products` | List all products with filters | No |
| GET | `/api/products/{product}` | Get product details | No |
| GET | `/api/categories` | List all categories | No |
| GET | `/api/categories/tree` | Get category tree structure | No |
| GET | `/api/categories/{category}` | Get category details | No |
| GET | `/api/categories/{category}/products` | Get products in a category | No |

## Product Sections

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| GET | `/api/product-sections` | Get all product sections | No |
| GET | `/api/products/by-type` | Get products by type | No |
| GET | `/api/products/by-type/{type}` | Get products by specific type | No |

## Cart Management

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| GET | `/api/cart` | Get user's cart items | Yes |
| POST | `/api/cart/add` | Add item to cart | Yes |
| PUT | `/api/cart/update/{item}` | Update cart item quantity | Yes |
| DELETE | `/api/cart/remove/{item}` | Remove item from cart | Yes |
| DELETE | `/api/cart/clear` | Clear entire cart | Yes |

## User Profile

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| GET | `/api/user` | Get authenticated user profile | Yes |
| PUT | `/api/user/profile` | Update user profile | Yes |

## User Notifications

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| GET | `/api/notifications` | Get user notifications | Yes |
| GET | `/api/notifications/unread/count` | Get count of unread notifications | Yes |
| GET | `/api/notifications/{id}` | Get specific notification | Yes |
| POST | `/api/notifications/{id}/read` | Mark notification as read | Yes |
| POST | `/api/notifications/read-all` | Mark all notifications as read | Yes |
| DELETE | `/api/notifications/{id}` | Delete notification | Yes |

## User Addresses

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| GET | `/api/users/{userId}/addresses` | Get user addresses | Yes |
| POST | `/api/users/{userId}/addresses` | Add new address | Yes |
| GET | `/api/users/{userId}/addresses/{addressId}` | Get specific address | Yes |
| PUT | `/api/users/{userId}/addresses/{addressId}` | Update address | Yes |
| DELETE | `/api/users/{userId}/addresses/{addressId}` | Delete address | Yes |
| PATCH | `/api/users/{userId}/addresses/{addressId}/default` | Set address as default | Yes |

## Orders & Checkout

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| POST | `/api/orders` | Create new order | Yes |
| GET | `/api/orders` | Get user orders | Yes |
| GET | `/api/orders/{order}` | Get order details | Yes |
| POST | `/api/orders/{order}/cancel` | Cancel order | Yes |
| GET | `/api/orders/{order}/pickup-details` | Get pickup details for order | Yes |

## Payments

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| GET | `/api/payments/methods` | Get available payment methods | Yes |
| POST | `/api/orders/{order}/payment` | Process payment for order | Yes |
| GET | `/api/payments/{payment}/verify` | Verify payment status | Yes |
| GET | `/api/payments/callback` | Payment callback URL | No |
| GET | `/api/payment/callback` | Alternative payment callback URL | No |
| GET | `/api/payments/callback/{status}` | Payment callback with status | No |
| GET | `/api/payment/callback/{status}` | Alternative payment callback with status | No |
| POST | `/api/webhooks/flutterwave` | Flutterwave webhook | No |

## Coupons

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| POST | `/api/coupons/validate` | Validate coupon code | No |

## Delivery

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| POST | `/api/delivery-fee/calculate` | Calculate delivery fee | No |

## Store Locations

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| GET | `/api/locations` | Get all store locations | No |
| GET | `/api/locations/nearby` | Get nearby store locations | No |
| GET | `/api/locations/{location}` | Get specific location details | No |

## Notification Bar

| Method | Endpoint | Description | Authentication Required |
|--------|----------|-------------|------------------------|
| GET | `/api/notification-bar` | Get active notification bar | No |

## Admin Routes

All admin routes require authentication and admin role.

### Dashboard

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/dashboard/stats` | Get dashboard statistics |
| GET | `/api/admin/dashboard/recent-orders` | Get recent orders |
| GET | `/api/admin/check-auth` | Verify admin authentication |

### Settings Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/settings` | Get all settings |
| PUT | `/api/admin/settings` | Update settings |
| GET | `/api/admin/settings/{key}` | Get specific setting |

### Delivery Settings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/delivery-settings/global` | Get global delivery settings |
| PUT | `/api/admin/delivery-settings/global` | Update global delivery settings |
| GET | `/api/admin/delivery-settings/store/{storeId}` | Get store delivery settings |
| PUT | `/api/admin/delivery-settings/store/{storeId}` | Update store delivery settings |

### Banner Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/banners` | Get all banners |
| POST | `/api/admin/banners` | Create new banner |
| GET | `/api/admin/banners/{id}` | Get banner details |
| PUT | `/api/admin/banners/{id}` | Update banner |
| DELETE | `/api/admin/banners/{id}` | Delete banner |
| POST | `/api/admin/banners/reorder` | Reorder banners |
| PUT | `/api/admin/banners/{id}/toggle-status` | Toggle banner status |

### Notification Bar Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/notification-bar` | Get notification bar |
| POST | `/api/admin/notification-bar` | Create notification bar |
| PUT | `/api/admin/notification-bar/{id}` | Update notification bar |
| DELETE | `/api/admin/notification-bar/{id}` | Delete notification bar |
| PUT | `/api/admin/notification-bar/{id}/toggle` | Toggle notification bar status |

### Product Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/products` | Get all products |
| POST | `/api/admin/products` | Create new product |
| GET | `/api/admin/products/{id}` | Get product details |
| PUT | `/api/admin/products/{id}` | Update product |
| DELETE | `/api/admin/products/{id}` | Delete product |
| PUT | `/api/admin/products/{id}/toggle-status` | Toggle product status |
| PUT | `/api/admin/products/{id}/toggle-featured` | Toggle product featured status |
| POST | `/api/admin/products/import` | Import products |
| GET | `/api/admin/products/export` | Export products |

### Category Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/categories` | Get all categories |
| POST | `/api/admin/categories` | Create new category |
| GET | `/api/admin/categories/{id}` | Get category details |
| PUT | `/api/admin/categories/{id}` | Update category |
| DELETE | `/api/admin/categories/{id}` | Delete category |
| POST | `/api/admin/categories/reorder` | Reorder categories |

### Order Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/orders` | Get all orders |
| GET | `/api/admin/orders/{id}` | Get order details |
| PUT | `/api/admin/orders/{id}/status` | Update order status |
| PUT | `/api/admin/orders/{id}/payment-status` | Update payment status |
| GET | `/api/admin/orders/export` | Export orders |

### User Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/users` | Get all users |
| GET | `/api/admin/users/{id}` | Get user details |
| PUT | `/api/admin/users/{id}` | Update user |
| DELETE | `/api/admin/users/{id}` | Delete user |
| PUT | `/api/admin/users/{id}/toggle-status` | Toggle user status |

### Coupon Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/coupons` | Get all coupons |
| POST | `/api/admin/coupons` | Create new coupon |
| GET | `/api/admin/coupons/{id}` | Get coupon details |
| PUT | `/api/admin/coupons/{id}` | Update coupon |
| DELETE | `/api/admin/coupons/{id}` | Delete coupon |
| PUT | `/api/admin/coupons/{id}/toggle-status` | Toggle coupon status |

### Message Campaign Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/message-campaigns` | Get all message campaigns |
| POST | `/api/admin/message-campaigns` | Create new message campaign |
| GET | `/api/admin/message-campaigns/{id}` | Get campaign details |
| PUT | `/api/admin/message-campaigns/{id}` | Update campaign |
| DELETE | `/api/admin/message-campaigns/{id}` | Delete campaign |
| POST | `/api/admin/message-campaigns/{id}/send` | Send campaign |
