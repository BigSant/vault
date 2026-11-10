import asyncio
import sys
from pathlib import Path
import unittest
from unittest.mock import AsyncMock,patch
import uuid
sys.path.insert(0,str(Path(__file__).parents[1]/'broker'))
import server

class BrokerConcurrencyTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):server.operations.clear()
    async def asyncTearDown(self):
        tasks=[op['task']for op in server.operations.values()]
        for task in tasks:task.cancel()
        await asyncio.gather(*tasks,return_exceptions=True);server.operations.clear()
    def body(self):return server.Start(id=uuid.uuid4(),entry_id=uuid.uuid4(),version=1,action='fixture.api')
    async def test_duplicate_start_after_authorization_wait_executes_once(self):
        barrier=asyncio.Event()
        async def authorize(op):await barrier.wait();return True
        body=self.body()
        with patch.object(server,'authorized',authorize),patch.object(server,'run',AsyncMock())as execute:
            calls=[asyncio.create_task(server.start(body))for _ in range(2)]
            await asyncio.sleep(0);barrier.set();await asyncio.gather(*calls);await asyncio.sleep(0)
            self.assertEqual(1,len(server.operations));self.assertEqual(1,execute.await_count)
    async def test_capacity_is_reserved_after_authorization_wait(self):
        barrier=asyncio.Event()
        async def authorize(op):await barrier.wait();return True
        with patch.object(server,'authorized',authorize),patch.object(server,'run',AsyncMock()):
            calls=[asyncio.create_task(server.start(self.body()))for _ in range(10)]
            await asyncio.sleep(0);barrier.set();results=await asyncio.gather(*calls,return_exceptions=True)
            self.assertEqual(4,len(server.operations))
            self.assertEqual(6,sum(isinstance(value,server.HTTPException)and value.status_code==429 for value in results))
