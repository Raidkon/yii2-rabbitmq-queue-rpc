(function() {
    var Single = null;

    function Server(host,user,password){
        this._host     = host; // ws://localhost:61614/stomp
        this._user     = user;
        this._password = password;
        this._types    = {
            'topic':(name, route) => { return '/exchange/' + name + (route?'/' + route:'');}
        };
        this._events   = ['error'];
        this._client   = null;

        this._eventCallback = {};
        this._connected     = null;

        this.autoReconect = 30;
        this.logging      = false;
        this._log('created');
    }
    Server.prototype = {
        on: function (event,callback) {
            if (!(event in this._eventCallback)){
                this._eventCallback[event] = [];
            }
            this._eventCallback[event].push(callback);
        },
        _emit: function (event, message,defaultCallback) {
            if (!(event in this._eventCallback)){
                if (defaultCallback){
                    defaultCallback(message);
                } else {
                    console.log(event,message);
                }
            } else {
                for (var i in this._eventCallback[event]) {
                    this._eventCallback[event][i](message);
                }
            }
        },
        _log: function (message){
            if (this.logging === true){
                console.log('ServerRpc: ',...arguments);
            }
        },
        _error: function (data, type) {
            this._emit('error',{type,data},message => console.error(message));
        },
        connect: function(){
            this._log('connect...');
            if (this._client !== null) {
                this._log('connect...ignore');
                return;
            }
            this._client = Stomp.client(this._host);
            var self = this;
            this._client.debug = function(){
                self._log(...arguments);
            };
            this._log('connect...client created');
            var headers = {
                login: this._user,
                passcode: this._password
            };
            this._connected = null;
            this._client.connect(headers,() => {
                this._log('connect...connect success');
                this._connected = true;
                this._emit('connected',true);
            },error => {
                this._log('connect...connect error');
                this._error(error,'connect');
                this._connected = false;
                if (this.autoReconect > 0){
                    this._log('connect...init autoreconct after ' + this.autoReconect + ' seconds');
                    setTimeout(() => {
                        this._log('connect...init autoreconct now!');
                        this._client = null;
                        this.connect();
                    },this.autoReconect * 1000);
                }
            });
        },
        _handleMessage:function(id,message,type,callbackMessage) {
            this._log('Handle message: ',id,', message: ',message);
            const json    = JSON.parse(message.body);
            const headers = 'headers' in message?message['headers']:{};
            let   user_id = 'user-id' in headers?headers['user-id']:null;
            if (!user_id){
                return this._error('User id not exists for message: ',message);
            }
            if (user_id.substr(0,7) === 'server.' && 'real_user_id' in headers){
                user_id = headers['real_user_id'];
            }
            if (!('destination' in headers)){
                return this._error('Destination not exists to headers: ',headers);
            }
            const destination = headers['destination'];
            this._log('destination: ',destination);
            let result = {
                id:id.id,
                headers,
                user_id
            };
            if (type === 'topic'){
                const dests = destination.split('/',4);
                if (dests.length !== 4){
                    return this._error('Error parse destination: ',destination);
                }
                const routeKey = dests[3];
                result = {
                    ...result,
                    ...this._parseRouteKey(routeKey)
                };
            }
            this._log(result);
            callbackMessage(json,result);
        },
        _parseRouteKey: function(routeKey) {
            let keys = routeKey.split('.');
            const command = [];
            const routeParams = {};
            let prevName = '';
            for (const index in keys){
                const value = keys[index];
                if (!isNaN(value)){
                    if (prevName !== ''){
                        routeParams[prevName] = +value;
                    }
                    prevName = '';
                } else {
                    command.push(value);
                    prevName = value;
                }
            }
            return {
                command:command.join('.'),
                routeParams
            }
        },
        subscribe: function (type, name, route, callbackMessage,callbackResult) {
            if (!(type in this._types)) {
                return this._error('type "' + type + '" not found!');
            }
            if (this._connected !== true){
                this.on('connected',() => this.subscribe(type, name, route, callbackMessage,callbackResult));
                this.connect();
                return;
            }
            const url = this._types[type](name,route);
            this._log('subscribe, type: ',type,', url: ',url);
            const id = this._client.subscribe(url,message => {
                this._log('recv message: ',url);
                this._handleMessage(id,message,type,callbackMessage)
            });
            if (callbackResult) {
                this._log('subscribed, id: ',id,'callback result');
                callbackResult(id);
            } else {
                this._log('subscribed, id: ',id,'not callback result');
            }
        }
    };

    ServerRpc = {
        single: function(host,user,password) {
            if (Single === null){
                Single = this.create(host,user,password);
            }
            return Single;
        },
        create: function(host,user,password) {
            return new Server(host,user,password);;
        },
    };

    if (typeof exports !== "undefined" && exports !== null) {
        exports.ServerRpc = ServerRpc;
    }

    if (typeof window !== "undefined" && window !== null) {
        window.ServerRpc = ServerRpc;
    } else if (!exports) {
        self.ServerRpc = ServerRpc;
    }

}).call(this);
