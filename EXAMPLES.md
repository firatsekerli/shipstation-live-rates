# Example Configuration

## Basic FedEx Configuration

This is a typical setup for a FedEx shipper:

```
Method Title: FedEx Shipping
API Key: [your-api-key]
API Secret: [your-api-secret]
Carrier Code: fedex
Service Codes: (leave empty to show all FedEx services)
Residential Delivery: ✓ (checked)
Markup Type: Fixed Amount
Markup Amount: 2.50
Fallback Amount: 15.00
Debug Mode: ✓ (for initial setup, uncheck after testing)
```

## Multiple Service Options

To show only Ground and 2-Day options:

```
Service Codes:
fedex_ground
fedex_2day
```

## Percentage Markup

To add 15% to all shipping rates:

```
Markup Type: Percentage
Markup Amount: 15
```

## For Your Frozen Tamale Business

Based on your screenshot and dry ice shipping needs:

```
Method Title: Temperature-Controlled Shipping
Carrier Code: fedex
Service Codes: (empty - to show all available options)
Residential Delivery: ✓ (checked - most customers are residential)
Markup Type: Fixed Amount
Markup Amount: 5.00 (to cover dry ice costs)
Fallback Amount: 25.00 (reasonable fallback for frozen shipping)
Debug Mode: ✓ (until you verify it's working correctly)
```

**Important for Frozen Products**:
- Make sure your product weights include packaging + dry ice weight
- Consider adding extra weight in product settings to account for insulated packaging
- The markup can help cover dry ice costs that aren't in the base shipping rate

## Testing Checklist

1. ✓ Store postal code is set in WooCommerce > Settings > General
2. ✓ Products have weight and dimensions
3. ✓ ShipStation API credentials are correct
4. ✓ Test with a real shipping address
5. ✓ Check debug logs for any errors
6. ✓ Compare rates to ShipStation's rate calculator
7. ✓ Test with different quantities to ensure weight calculation is correct
8. ✓ Test residential vs commercial addresses if applicable

## Weight Considerations for Frozen Products

If you're shipping frozen tamales with dry ice:

**Example Product Setup**:
- Tamales: 2 lbs
- Packaging/Insulation: 1 lb  
- Dry Ice: 5 lbs (for 2-day shipping)
- **Total Product Weight**: 8 lbs

You can either:
1. Add the full weight (8 lbs) to your product, or
2. Add only tamale weight (2 lbs) and use markup to compensate

Option 1 is more accurate for rate calculations.

## API Endpoint Used

This plugin uses: `https://ssapi.shipstation.com/shipments/getrates`

This is the `/rates/estimates/` endpoint mentioned in your screenshot, which provides:
- ✓ Faster response times
- ✓ Less overhead on ShipStation
- ✓ Includes `otherCost` in calculations (automatically handled)
- ✓ Estimates that are usually accurate unless there's misconfiguration
