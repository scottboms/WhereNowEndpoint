# Where Now? Remote Endpoint

[Where Now?](https://wherenow.scottboms.com) is an intentionally simple app for iOS designed to let you see where you’ve been, creating a useful dataset for personal and private analysis.

This self-hosted PHP-based endpoint works with the companion iOS app to remotely store location data which can be connected to other geolocation services like MapBox or Apple's own MapKit JS libraries.

## Requirements

* Apache Web Server with PHP 8.x
* Apache Server read/write access and permissions control

## Set Up

1. Copy the Endpoint code to your remote web server. The main folder can be renamed however you'd like but must be reachable by the app.
2. Set the appropriate `LOG_FILE` and `TOKEN` configuration parameters in the included `config.php` file. You can use the following the generate a `TOKEN` but if you want to use a different process, you can -- just keep this secret.

  ```bash
  openssl rand -base64 32 | tr '+/' '-_' | tr -d '='
  ```

3. Create a folder where the endpoint will log data to. This ideally is outside of your webserver root directory. Set the folder ownership and group to `www-data` or the username for your http server.

  ```bash
  chown -R www-data:www-data /path/to/your/log/file.jsonl
	chmod -R 750 /path/to/your/log/file.jsonl
  ```

## Testing Connectivity

You can confirm connectivity to the endpoint using the following shell commands.

**Save a Location to the Log**

```bash
curl -X POST "https://your-server.com/wherenow/" \
  -H "Authorization: Bearer YOUR_ENDPOINT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":"8f84f6af-f7e1-4db7-9c93-16d89f2a45db","lat":33.812078,"lon":-117.918963,"timestamp":"2026-01-01T12:00:00Z","accuracy":12,"label":"Disneyland","note":"Main gate","reason":"upload"}'
```

This should return a JSON response of `{'ok':true}`.

**Patch Saved Location Metadata**

```bash
curl -X PATCH "https://your-server.com/wherenow/" \
  -H "Authorization: Bearer YOUR_ENDPOINT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":"8f84f6af-f7e1-4db7-9c93-16d89f2a45db","label":"Disneyland Entrance","note":"Updated note text"}'
```

This should return a JSON response of `{'ok':true,...}`.

## License

MIT License.
