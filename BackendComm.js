if(typeof BackendComm === 'undefined') {
	var BackendComm = class{
		#plugin;
		#params = {};
		constructor(plugin) {
			this.#plugin = plugin;
		}

		static async process_request(fetch_promise) {
			let answer;  // it's const, but JS…
			try {
				const response = await fetch_promise;
				if(! response.ok) {
					Notify.error(`HTTP error ${response.status} for ${response.url}: check error console.`);
					console.error(response);
					return;
				}

				answer = await response.json();
			} catch(e) {
				console.error(e);
				Notify.error(`Error while fetching ${response.url}. Check console for details.`);
				return;
			}

			if(answer.hasOwnProperty('error') ) {  // ttrss errors
				Notify.error(answer.error.message);
				console.error(answer);
				return;
			}

			if(answer.hasOwnProperty('errMsg')) {  // our own errors
				Notify.error(answer.errMsg);
				console.error(answer);
				//return;
			}

			if(answer.hasOwnProperty('msg')) {
				Notify.info(answer.msg);
			}

			return answer;
		}

		clear_params() {
			this.#params = {};
		}

		add_dojo_params(node) {
			if(! node.validate()) {
				const s = "Validation failed!";
				console.error(s, node);
				throw s + " Check console.";
			}

			let val = node.getValues();
			console.debug("Got dojo values: ", val);
			this.add_params(val);

			return val;
		}

		add_params(params) {
			Object.assign(this.#params, params);
		}

		send_post_request(method='') {
			const params = this.#params;
			params.op = "pluginhandler";
			params.plugin = this.#plugin;
			if(method) params.method = method;

			let req = new Request('backend.php', {method: "POST", body: new URLSearchParams(params)});
			console.debug("Prepared backend request: ", req, " with body: ", params);
			this.clear_params();
			return fetch(req);
		}

		post_notify(method='', params={}, node=null) {
			if(node) this.add_dojo_params(node);
			this.add_params(params);
			const prom = this.send_post_request(method);
			return BackendComm.process_request(prom);
		}
	};
}
