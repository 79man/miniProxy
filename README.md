# miniProxy

*by Joshua Dick*

*[http://joshdick.github.io/miniProxy][1]*

---

## About miniProxy

miniProxy is a simple web proxy written in PHP that can allow you to bypass Internet content filters, or to browse the internet anonymously. miniProxy is licensed under the [GNU GPL v3][2]. miniProxy is the successor to [pageForward][3].

## Prerequisites

miniProxy should be able to run on any web server with PHP 5.4.7 or later. PHP's cURL extension must be installed.

## Installation and Use

Simply copy `miniProxy.php` to your web server (it's okay to rename it) and access it directly. That's it! You'll be presented with further usage instructions.

miniProxy doesn't require any configuration out of the box, but configuration options are available; see the top of `miniProxy.php` for details.

## Known Limitations

miniProxy has several known limitations. Some of them may be fixed in future releases. For now, they include:

* `<object>` tags are not handled
* No cookie support
* Basic AJAX support, but only for browsers that use `XMLHttpRequest`

## Additional Improvements

* Support for $.ajax added
* Support for window.open added
* Eliminated warnings caused by presence of &amp; in the action field of forms
* Support to prevent re-proxification of already proxified URLs in client javascript
* Support for eliminating fails due to $_=XXXXX string added to the querystring during testing
* Support for logging modified URLs to file, and addition of clear logs button on the main page

## Contact and Feedback

If you'd like to contribute to miniProxy or file a bug or feature request, please visit [its GitHub page][4].

  [1]: http://joshdick.github.io/miniProxy
  [2]: http://www.gnu.org/licenses/gpl.html
  [3]: http://pageforward.sf.net
  [4]: https://github.com/joshdick/miniProxy
