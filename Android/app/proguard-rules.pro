# Keep the Android framework and app entry points intact.
-keep class pt.smsapi.gateway.GatewayApp { *; }
-keep class pt.smsapi.gateway.service.SmsGatewayService { *; }
-keep class pt.smsapi.gateway.service.BootReceiver { *; }
-keep class pt.smsapi.gateway.sms.SmsResultReceiver { *; }

# org.json is part of the platform but referenced reflectively by some APIs.
-dontwarn org.json.**