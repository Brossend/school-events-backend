"""Small live gateway probes; secrets are read locally, never persisted in evidence."""
from pathlib import Path
import concurrent.futures, json, re, time, urllib.request, urllib.error

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT.parent/'schoolFrontend/docs/llm001'
KEY=re.search(r'^LITELLM_API_KEY=(.+)$',(ROOT/'.env.testing').read_text(encoding='utf-8'),re.M)[1].strip()
BASE='https://litellmru.ds-hub-cf.ru'

def call(path,body=None,auth=True):
    start=time.monotonic()
    req=urllib.request.Request(BASE+path,data=json.dumps(body,ensure_ascii=False).encode() if body else None,
        headers={'Accept':'application/json','Content-Type':'application/json',**({'Authorization':'Bearer '+KEY} if auth else {})})
    try:
        with urllib.request.urlopen(req,timeout=60) as response: status=response.status;raw=response.read().decode();retry=response.headers.get('Retry-After')
    except urllib.error.HTTPError as e: status=e.code;raw=e.read().decode();retry=e.headers.get('Retry-After')
    except Exception as e:return {'path':path,'status':None,'error':type(e).__name__,'latency_ms':round((time.monotonic()-start)*1000)}
    raw=raw.replace(KEY,'[REDACTED]')
    try:data=json.loads(raw)
    except ValueError:data={'unparsed':raw[:1000]}
    return {'path':path,'status':status,'latency_ms':round((time.monotonic()-start)*1000),'retry_after':retry,'response':data}

def probe(model):
    schema={'type':'object','additionalProperties':False,'required':['title','stages'],
            'properties':{'title':{'type':'string'},'stages':{'type':'array','items':{'type':'object','additionalProperties':False,'required':['title','minutes'],'properties':{'title':{'type':'string'},'minutes':{'type':'integer'}}}}}}
    body={'model':model,'temperature':0.3,'max_tokens':512,'stream':False,
          'messages':[{'role':'system','content':'Ты педагог-методист. Верни только JSON по схеме, без рассуждений.'},
                      {'role':'user','content':'Синтетический smoke-test. Предложи два этапа классного часа о взаимопомощи для 5 класса, на 15 и 30 минут. Название и действия должны быть конкретными, на русском языке.'}],
          'response_format':{'type':'json_schema','json_schema':{'name':'pedagogical_smoke','strict':True,'schema':schema}}}
    result=call('/v1/chat/completions',body);result['model']=model;result['requested_mode']='json_schema';result['request']=body
    return result

if __name__=='__main__':
    models=['Qwen3.8-27B','GigaChat-3-Pro','GigaChat-3-Lightning']
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool: results=list(pool.map(probe,models))
    info=call('/v1/model/info')
    # This endpoint may expose provider routing credentials: persist only public limits.
    filtered=[]
    for model in info.get('response',{}).get('data',[]):
        limits={k:v for k,v in model.get('model_info',{}).items() if k in {'max_tokens','max_input_tokens','max_output_tokens','supports_response_schema','supports_function_calling','mode','input_cost_per_token','output_cost_per_token'}}
        filtered.append({'model_name':model.get('model_name'),'limits':limits})
    info={'status':info['status'],'latency_ms':info['latency_ms'],'models':filtered}
    unauthorized=call('/v1/models',auth=False)
    (OUT/'gateway-smoke.json').write_text(json.dumps({'probes':results,'model_info':info,'unauthorized':unauthorized},ensure_ascii=False,indent=2),encoding='utf-8')
    print(json.dumps([{'model':r['model'],'status':r['status'],'latency_ms':r['latency_ms'],'finish_reason':(r.get('response',{}).get('choices') or [{}])[0].get('finish_reason'),'usage':r.get('response',{}).get('usage'),'error':r.get('response',{}).get('error',{}).get('message','')[:180]} for r in results],ensure_ascii=True,indent=2))
