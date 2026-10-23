# WP2 Shopify Identity Documentation

## Nonce Lifecycle
The nonce is a security token used to validate requests to the WordPress REST API. It is included in the `X-WP-Nonce` header for all authenticated requests.

### Key Points:
- Nonces are generated on the server and passed to the client via the `wp2Config.rest_nonce` variable.
- Nonces expire after 24 hours by default.
- If a nonce expires, the client will attempt to refresh it by reloading the page.

### Troubleshooting:
- Ensure the `wp2Config.rest_nonce` variable is correctly passed to the client.
- Check for 403 errors in the browser console, which indicate an expired nonce.

## Token Flow
The token flow involves exchanging authorization codes for access tokens and refreshing tokens when they expire.

### Key Points:
- Tokens are securely stored in HTTP-only cookies.
- The `/auth/refresh` endpoint is used to refresh tokens when they expire.
- Token refresh logic prevents duplicate refresh requests and handles 401 errors gracefully.

### Troubleshooting:
- Check the browser console for errors during token refresh.
- Ensure the `wp2Fetch` utility is correctly handling 401 and 403 responses.

## REST API Expectations
The REST API is used for communication between the WordPress backend and the Shopify Customer Account API.

### Key Points:
- All endpoints require a valid nonce in the `X-WP-Nonce` header.
- Responses include detailed error messages for debugging.
- The `wp_safe_remote_get` and `wp_safe_remote_post` functions are used for secure communication with Shopify.

### Troubleshooting:
- Verify that the `X-WP-Nonce` header is included in all requests.
- Check the server logs for errors related to REST API requests.

## Error Formats
Errors returned by the REST API follow a standard format:

```json
{
  "success": false,
  "message": "Error message here",
  "debug": "Optional debug information"
}
```

### Key Points:
- The `success` field indicates whether the request was successful.
- The `message` field contains a user-friendly error message.
- The `debug` field contains additional information for debugging (if available).

### Troubleshooting:
- Check the `debug` field for additional error details.
- Ensure the REST API endpoints are correctly configured and accessible.

---

For further assistance, please refer to the plugin's README file or contact support.