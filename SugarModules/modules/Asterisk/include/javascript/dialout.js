$(document).ready(function () {
    //.asterisk_phoneNumber is the deprecated v1.x class
    $('.phone,#phone_work,#phone_other,#phone_mobile,.asterisk_phoneNumber').each(function () {
      var phoneNr = $(this).text();
      phoneNr = $.trim(phoneNr); // IE needs trim on separate line.

      // Regex searches the inner html to see if a child element has phone class,
      // this prevents a given number having more then one click to dial icon.
      // ? after the " is required for IE compatibility.  IE strips the " around the class names apparently.
      // The /EDV.show_edit/ regex allows it to work with Letrium's Edit Detail View module.
      if (phoneNr.length > 1 && ( !/(class="?phone"?|id="?#phone|class="?asterisk_placeCall"?)/.test($(this).html()) || /EDV.show_edit/.test($(this).html()) )) {
      var contactId = $('input[name="record"]', document.forms['DetailView']).attr('value');
      if (!contactId) {
      contactId = $('input[name="mass[]"]', $(this).parents('tr:first')).attr('value');
      }

      if (window.callinize_user_extension) {
      $(this).append('<div title="Extension Configured for Click To Dial is: ' + window.callinize_user_extension + '" class="asterisk_placeCall activeCall" record="' + contactId + '" value="anrufen" style="cursor: pointer;"></div>');
      }
      else {
      $(this).append('&nbsp;&nbsp;<img title="No extension configured!  Go to user preferences to set your extension" src="custom/modules/Asterisk/include/images/call_noextset.gif" class="asterisk_placeCall" value="anrufen" style="cursor: pointer;"/>&nbsp;');
      }

      $('.asterisk_placeCall', this).click(function () {

          var record = $(this).attr('record');
          //change to spinner
          $("div").find("[record='" + record + "']").removeClass('activeCall').addClass('dialedCall');

          var call = $.get('index.php?entryPoint=AsteriskCallCreate',
            {phoneNr: phoneNr, contactId: contactId, module:getModule()},
            function (data) {
            console.log("CreateCall Action Response: " + data);
            if (data.indexOf('Error') != -1 || data.indexOf("ERROR") != -1) {
              alert("Click to Dial Failed:\n***\n" + data + "\n***\n");
            }
            call = null;
            });

          setTimeout(function () {
            $("div").find("[record='" + record + "']").removeClass('dialedCall').addClass('activeCall');
          }, 5000);

          setTimeout(function () {
            if (call) {
              call.abort();
            }
          }, 20000);
      });
    }

    function getModule(){
      if(window.module_sugar_grp1 == undefined){
        return getUrlParam('module');
      }else{
        return window.module_sugar_grp1;
      }
    }

    //internal function to assist in getting sugarcrm url parameters
    function getUrlParam(name) {

      var decodedUri = decodeURIComponent(window.location.href);
      var results = new RegExp('[\\?&]' + name + '=([^&#]*)').exec(decodedUri);
      if (!results) {
        return null;
      } else {
        return results[1];
      }
    }
    });
});
