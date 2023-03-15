# TwitterApi

Simple class for making requests to the Twitter API.  Not affiliated with Twitter.

## Usage

```php
use cjrasmussen\TwitterApi\TwitterApi;

$twitter = new TwitterApi($consumer_key, $consumer_secret);

// SEND A TWEET WITH OAUTH TOKEN/SECRET
$twitter->auth(TwitterApi::AUTH_TYPE_OAUTH, $token, $secret);
$response = $twitter->request('POST', '1.1/statuses/update.json', ['status' => 'Tweet text']);

// SEND A TWEET WITH BEARER TOKEN
$twitter->auth(TwitterApi::AUTH_TYPE_BEARER, $token);
$response = $twitter->request('POST', '1.1/statuses/update.json', ['status' => 'Tweet text']);
```

## More Examples

More examples, as well as other things I've learned using the Twitter API, are [available at my blog](https://cjr.dev/tag/trello-automation/).

## Installation

Simply add a dependency on cjrasmussen/twitter-api to your composer.json file if you use [Composer](https://getcomposer.org/) to manage the dependencies of your project:

```sh
composer require cjrasmussen/twitter-api
```

Although it's recommended to use Composer, you can actually include the file(s) any way you want.


## License

TwitterApi is [MIT](http://opensource.org/licenses/MIT) licensed.