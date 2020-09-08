NCIConnectTokenAndPinGenerator.doBatchCommand = function (actionname) {
    if (NCIConnectTokenAndPinGenerator.batchServiceURL) {
        $.ajax({
            url: NCIConnectTokenAndPinGenerator.batchServiceURL,
            data: { "action": actionname },
            type: 'POST',
            timeout : 5000,
            success: function (returnData) {
                //Refresh the current page to display batch status
                window.location.reload();
            },
            error: function(request, status, err) {
                if (status == "timeout") {
                    //Refresh the current page (batch job may already submitted)
                    window.location.reload();
                } else {
                    // another error occured  
                    console.log("error: " + request + status + err);
                }
            }

        });

    }
};


 