# ShipStation Live Rates for WooCommerce

A WordPress plugin that integrates ShipStation's live shipping rates API with WooCommerce, providing real-time shipping cost calculations at checkout.

## Features

- **Live Rate Calculations**: Get real-time shipping rates from ShipStation's `/rates/estimates/` endpoint
- **Dynamic Carrier Detection**: Automatically fetches only carriers enabled in your ShipStation account
- **Multiple Carriers**: Support for all ShipStation carriers (FedEx, UPS, USPS, DHL, Canada Post, Stamps.com, and more)
- **Service Filtering**: Display only specific shipping services
- **Flexible Markup**: Add fixed or percentage-based markup to rates
- **Automatic Conversions**: Handles weight and dimension unit conversions
- **Residential/Commercial**: Toggle between residential and commercial delivery rates
- **Other Costs**: Automatically includes `other_amount` charges from ShipStation
- **Fallback Rates**: Set a fallback flat rate if API requests fail
- **Debug Logging**: Built-in logging for troubleshooting
- **Smart Caching**: Carrier list cached for 24 hours to optimize performance
- **WooCommerce Shipping Zones**: Full integration with WooCommerce shipping zones

## Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- ShipStation account with API access

## Installation

1. **Upload the Plugin**:
   - Upload the `shipstation-live-rates` folder to `/wp-content/plugins/`
   - Or install via WordPress admin: Plugins > Add New > Upload Plugin

2. **Activate**:
   - Go to Plugins > Installed Plugins
   - Click "Activate" under ShipStation Live Rates

3. **Get ShipStation API Credentials**:
   - Log in to your ShipStation account
   - Go to Settings > Account > API Settings
   - Copy your API Key and API Secret

## Configuration

### 1. Set Up Shipping Zone

1. Go to **WooCommerce > Settings > Shipping**
2. Click on a shipping zone or create a new one
3. Click **Add shipping method**
4. Select **ShipStation Live Rates**
5. Click **Add shipping method**

### 2. Configure ShipStation Settings

Click on **ShipStation Live Rates** to configure:

#### Basic Settings

- **Enable/Disable**: Enable the shipping method
- **Method Title**: The title customers see at checkout (e.g., "Shipping")

#### API Credentials

- **API Key**: Your ShipStation API Key
- **API Secret**: Your ShipStation API Secret

#### Carrier Settings

- **Carrier Code**: Select your carrier from the dropdown
  - The list shows **only carriers enabled in your ShipStation account**
  - Carriers are automatically fetched via ShipStation API
  - List is cached for 24 hours for performance
  - To refresh the carrier list, save the settings form

- **Service Codes**: (Optional) Filter specific services, one per line:
  ```
  fedex_ground
  fedex_2day
  fedex_standard_overnight
  ```
  Leave empty to show all available services for the carrier

- **Residential Delivery**: Check if most deliveries are to residential addresses (affects rates)

#### Pricing Options

- **Markup Type**: 
  - None: Show exact ShipStation rates
  - Fixed Amount: Add a flat fee (e.g., $5.00)
  - Percentage: Add a percentage markup (e.g., 10%)

- **Markup Amount**: Enter the amount for markup (5 for $5 or 10 for 10%)

#### Fallback & Debug

- **Fallback Amount**: A flat rate to charge if the API fails (optional but recommended)
- **Debug Mode**: Enable to log API requests/responses to WooCommerce logs

### 3. Configure Store Settings

Make sure your store settings are complete:

1. **WooCommerce > Settings > General**:
   - Store Address
   - Store Postcode (required for rate calculations)
   - Store City
   - Store State

2. **WooCommerce > Settings > Products**:
   - Set Weight Unit (oz, lbs, kg, g)
   - Set Dimension Unit (in, cm, m)

### 4. Configure Product Weights & Dimensions

For accurate rates, products need weight and dimensions:

1. Edit each product
2. Go to **Product Data > Shipping**
3. Enter:
   - Weight
   - Length, Width, Height

## How It Works

1. **Customer Checkout**: When a customer reaches checkout, the plugin collects:
   - Destination address (city, state, postal code, country)
   - Cart contents (products, quantities)
   - Product weights and dimensions

2. **API Request**: The plugin sends a request to ShipStation's `/rates/estimates/` endpoint with:
   - Origin (your store address)
   - Destination (customer address)
   - Package weight (calculated from cart)
   - Package dimensions (largest item dimensions)
   - Carrier and service preferences

3. **Rate Calculation**: ShipStation returns available shipping services with costs:
   - Base `shipmentCost`
   - Additional `otherCost` (if applicable)
   - Service name and code

4. **Display**: The plugin:
   - Adds any configured markup
   - Displays rates to the customer
   - Customer selects preferred shipping method

## Service Code Examples

### FedEx
```
fedex_ground
fedex_2day
fedex_standard_overnight
fedex_priority_overnight
fedex_first_overnight
fedex_express_saver
```

### UPS
```
ups_ground
ups_3_day_select
ups_2nd_day_air
ups_next_day_air
ups_next_day_air_saver
```

### USPS
```
usps_priority_mail
usps_first_class_mail
usps_priority_mail_express
usps_media_mail
usps_parcel_select
```

## Troubleshooting

### Carrier Not Showing in Dropdown

1. **Verify Carrier is Enabled in ShipStation**:
   - Log in to ShipStation
   - Go to Settings > Shipping > Carriers
   - Ensure the carrier is connected and enabled

2. **Refresh Carrier Cache**:
   - Go to the shipping method settings
   - Click "Save changes" to refresh the carrier list
   - The cache automatically updates every 24 hours

3. **Check API Credentials**:
   - Verify API Key and Secret have correct permissions
   - Ensure credentials can access carrier information

### No Rates Showing

1. **Check API Credentials**:
   - Verify API Key and Secret are correct
   - Test in ShipStation's API documentation

2. **Enable Debug Mode**:
   - Turn on Debug Mode in settings
   - Check logs: WooCommerce > Status > Logs
   - Look for `shipstation-live-rates` log file

3. **Verify Store Settings**:
   - Store postal code is set
   - Store address is complete

4. **Check Product Data**:
   - Products have weights
   - Products have dimensions (length, width, height)

5. **Test Destination**:
   - Try with a known valid US postal code
   - Check if destination is within carrier service area

### Rates Too High/Low

1. **Check Product Weights**: Verify weights are correct and in proper units
2. **Check Dimensions**: Ensure dimensions are accurate
3. **Review Markup Settings**: Adjust markup type and amount
4. **Residential vs Commercial**: Toggle residential setting

### API Errors

Common errors and solutions:

- **Invalid Postal Code**: Verify destination postal code format
- **Authentication Failed**: Check API credentials
- **Rate Limit Exceeded**: ShipStation has rate limits; consider caching (future feature)
- **No Service Available**: Destination may be outside service area for selected carrier

## Support

For issues or questions:

1. Check the debug logs first
2. Verify all configuration steps are complete
3. Test with a simple single-item cart
4. Contact support with:
   - Plugin version
   - WooCommerce version
   - Error messages from logs
   - API response (with credentials removed)

## Changelog

### 1.1.0
- **New**: Dynamic carrier detection - automatically fetches only enabled carriers from ShipStation account
- **New**: Smart caching of carrier list (24 hours) for improved performance
- **New**: Automatic carrier list refresh when saving settings
- **Improved**: Better user experience - only shows relevant carriers

### 1.0.0
- Initial release
- Live rate calculations via ShipStation API
- Support for multiple carriers
- Service filtering
- Markup options
- Fallback rates
- Debug logging
- Unit conversions

## Credits

Developed for WooCommerce integration with ShipStation's shipping rate API.

## License

GPL v2 or later
